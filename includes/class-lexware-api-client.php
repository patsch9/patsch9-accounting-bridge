<?php
/**
 * Lexware API Client
 * Handhabt alle Kommunikation mit der Lexware Office Public API
 */
if (!defined('ABSPATH')) {
    exit;
}
class PATSACBR_API_Client {
    const API_BASE_URL = 'https://api.lexware.io/v1/';
    const RATE_LIMIT_REQUESTS = 2; // 2 requests pro Sekunde
    const RATE_LIMIT_WINDOW = 1; // 1 Sekunde
    const MAX_RETRIES = 3; // Exponential Backoff: max 3 Retries
    const VOUCHER_PREFIX = 'Wertgutschein'; // Germanized Wertgutschein Präfix

    private $api_key;
    private $last_request_time = 0;
    private $request_times = array(); // Für Tracking der Requests im Zeitfenster
    public function __construct() {
        $key = defined('PATSACBR_LEXWARE_API_KEY') && is_scalar(PATSACBR_LEXWARE_API_KEY)
            ? trim((string) PATSACBR_LEXWARE_API_KEY)
            : (string) get_option('patsacbr_api_key', '');
        $this->api_key = class_exists('PATSACBR_Security') ? PATSACBR_Security::sanitize_api_key($key) : sanitize_text_field($key);
    }
    private function is_valid_uuid($value) {
        return is_string($value) && (bool) preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5a-f0-9][a-f0-9]{3}-[89ab0-9a-f][a-f0-9]{3}-[a-f0-9]{12}$/i', $value);
    }
    private function format_lexware_date($timestamp) {
        $date = new DateTime();
        $date->setTimestamp($timestamp);
        $date->setTimezone(new DateTimeZone('Europe/Berlin'));
        $date->setTime(0, 0, 0);
        return $date->format('Y-m-d\TH:i:s.000P');
    }
    /**
     * Respektiert Rate Limits und wartet bei Bedarf
     */
    private function enforce_rate_limit() {
        $now = microtime(true);

        // Entferne alte Einträge außerhalb des Zeitfensters
        $this->request_times = array_filter($this->request_times, function($time) use ($now) {
            return ($now - $time) < self::RATE_LIMIT_WINDOW;
        });

        // Wenn wir das Limit erreicht haben, warte
        if (count($this->request_times) >= self::RATE_LIMIT_REQUESTS) {
            // Warte auf den ältesten Request
            $oldest = min($this->request_times);
            $wait_time = self::RATE_LIMIT_WINDOW - ($now - $oldest) + 0.01; // 10ms Puffer

            if ($wait_time > 0) {
                usleep($wait_time * 1000000); // Convert to microseconds
                $this->request_times = array(); // Reset nach Warten
            }
        }

        // Tracke diesen Request
        $this->request_times[] = microtime(true);
    }
    /**
     * Prüft, ob ein Item ein Wertgutschein ist (Germanized Plugin)
     *
     * Wertgutscheine werden von Germanized mit einem Präfix gekennzeichnet.
     * Sie sind ohne MwSt. und werden erst bei Einlösung besteuert.
     *
     * @param WC_Order_Item $item Das Order Item
     * @return bool True wenn Wertgutschein, false sonst
     */
    private function is_value_voucher_item($item) {
        $item_name = $item->get_name();
        return strpos($item_name, self::VOUCHER_PREFIX) === 0;
    }
    /**
     * Extrahiert Wertgutschein-Informationen aus Order Items
     *
     * Germanized speichert Wertgutscheine als 'fee' Items mit negativem Betrag.
     * Wir sammeln alle Wertgutschein-Items und geben sie als Array zurück.
     *
     * @param WC_Order $order Die WooCommerce Bestellung
     * @return array Array mit Wertgutschein-Daten (amount, description)
     */
    private function get_voucher_items_from_order($order) {
        $vouchers = array();

        // Suche in line_items
        foreach ($order->get_items() as $item) {
            if ($this->is_value_voucher_item($item)) {
                $voucher_name = is_callable(array($item, 'get_code')) ? $item->get_code() : $item->get_name();
                $vouchers[] = array(
                    'amount' => abs((float)$item->get_total()),
                    'name' => sanitize_text_field((string)$voucher_name)
                );
            }
        }

        // Suche in fee items (wo Germanized Wertgutscheine oft gespeichert werden!)
        foreach ($order->get_items('fee') as $fee_item) {
            if ($this->is_value_voucher_item($fee_item)) {
                $vouchers[] = array(
                    'amount' => abs((float)$fee_item->get_total()),
                    'name' => $fee_item->get_name()
                );
            }
        }

        return $vouchers;
    }
    /**
     * Prüft, ob ein Lexware-Kontakt die E-Mail-Adresse exakt enthält.
     * Die Contacts-API filtert E-Mails als Substring/Pattern; deshalb darf das
     * erste Suchergebnis nicht ungeprüft übernommen werden.
     */
    private function contact_has_exact_email($contact, $email) {
        if (!is_array($contact)) {
            return false;
        }
        $needle = strtolower(trim((string) $email));
        if ('' === $needle) {
            return false;
        }

        $candidates = array();
        if (!empty($contact['emailAddresses']) && is_array($contact['emailAddresses'])) {
            foreach ($contact['emailAddresses'] as $values) {
                if (!is_array($values)) {
                    continue;
                }
                foreach ($values as $value) {
                    if (is_scalar($value)) {
                        $candidates[] = (string) $value;
                    }
                }
            }
        }
        if (!empty($contact['company']['contactPersons']) && is_array($contact['company']['contactPersons'])) {
            foreach ($contact['company']['contactPersons'] as $person) {
                if (is_array($person) && isset($person['emailAddress']) && is_scalar($person['emailAddress'])) {
                    $candidates[] = (string) $person['emailAddress'];
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (strtolower(trim($candidate)) === $needle) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sucht einen bestehenden aktiven Kundenkontakt anhand einer exakten E-Mail-Adresse.
     *
     * @param string $email Die E-Mail-Adresse zum Suchen.
     * @return array|null|WP_Error Kontakt, null bei sicherem Nichtfund oder WP_Error bei API-/Mehrdeutigkeitsfehlern.
     */
    public function find_contact_by_email($email) {
        $email = sanitize_email(trim((string) $email));
        if (strlen($email) < 3) {
            return null;
        }

        // Lexware interpretiert _ und % im Filter als Pattern-Zeichen. Vor dem
        // URL-Encoding müssen sie mit Backslash escaped werden.
        $filter_email = strtr($email, array('\\' => '\\\\', '_' => '\\_', '%' => '\\%'));
        $result = $this->request('GET', 'contacts?email=' . rawurlencode($filter_email) . '&customer=true');
        if (is_wp_error($result)) {
            // Fail closed: a failed lookup must not be treated as "not found", otherwise
            // a transient API problem could create duplicate Lexware contacts.
            return $result;
        }

        $matches = array();
        if (!empty($result['content']) && is_array($result['content'])) {
            foreach ($result['content'] as $contact) {
                if (!is_array($contact) || !$this->contact_has_exact_email($contact, $email)) {
                    continue;
                }
                if (empty($contact['roles']['customer'])) {
                    continue;
                }
                if (!empty($contact['archived'])) {
                    return new WP_Error(
                        'archived_contact_match',
                        __('An archived customer contact with this email address already exists in Lexware. Please check the contact in Lexware before creating a new invoice.', 'patsch9-accounting-bridge'),
                        array('retryable' => false)
                    );
                }
                if (empty($contact['id']) || !$this->is_valid_uuid((string) $contact['id'])) {
                    return new WP_Error('invalid_contact_search_response', __('Lexware did not return a valid contact ID during the contact search.', 'patsch9-accounting-bridge'), array('retryable' => false));
                }
                $matches[] = $contact;
            }
        }

        // Mehrere exakte Treffer sind ein Datenqualitätsproblem. Nicht willkürlich
        // den ersten Kontakt verknüpfen, sondern eine manuelle Klärung erzwingen.
        if (count($matches) > 1) {
            return new WP_Error(
                'ambiguous_contact_match',
                __('Multiple customer contacts with exactly the same email address exist in Lexware. Please resolve the duplicates in Lexware.', 'patsch9-accounting-bridge'),
                array('retryable' => false)
            );
        }

        return $matches ? $matches[0] : null;
    }

    public function sync_contact($order) {
        $existing_contact_id = sanitize_text_field((string) $order->get_meta('_patsacbr_lexware_contact_id'));

        // Bereits verknüpfte Kontakte nur verifizieren. Bestehende Lexware-Kontakte
        // werden bewusst nicht automatisch per PUT überschrieben: die API behandelt
        // Updates als vollständige Kontaktänderung und bestehende Rollen, XRechnung-
        // Daten, Notizen oder weitere Kontaktangaben könnten sonst verloren gehen.
        if ($existing_contact_id) {
            if (!$this->is_valid_uuid($existing_contact_id)) {
                return new WP_Error('invalid_contact_id', __('Invalid stored Lexware contact ID.', 'patsch9-accounting-bridge'), array('retryable' => false));
            }
            $existing = $this->request('GET', 'contacts/' . rawurlencode($existing_contact_id));
            if (is_wp_error($existing)) {
                return $existing;
            }
            if (!empty($existing['archived'])) {
                return new WP_Error('archived_contact', __('The Lexware contact linked to this order is archived. Please check the link in Lexware.', 'patsch9-accounting-bridge'), array('retryable' => false));
            }
            if (empty($existing['roles']['customer'])) {
                return new WP_Error('contact_not_customer', __('The Lexware contact linked to this order does not have a customer role.', 'patsch9-accounting-bridge'), array('retryable' => false));
            }
            return $existing;
        }

        // Ohne gespeicherte ID zuerst sicher nach einem exakten aktiven Kundenkontakt suchen.
        $existing_contact = $this->find_contact_by_email($order->get_billing_email());
        if (is_wp_error($existing_contact)) {
            return $existing_contact;
        }
        if ($existing_contact) {
            $contact_id = sanitize_text_field((string) $existing_contact['id']);
            $order->update_meta_data('_patsacbr_lexware_contact_id', $contact_id);
            $order->save();
            return $existing_contact;
        }

        // Nur bei einem sicher bestätigten Nichtfund wird ein neuer Kontakt erzeugt.
        $contact_data = array(
            'version' => 0,
            'roles' => array(
                'customer' => new stdClass()
            )
        );

        $billing_company = trim((string) $order->get_billing_company());
        $is_company = '' !== $billing_company;
        if ($is_company) {
            $last_name = trim((string) $order->get_billing_last_name());
            if ('' === $last_name) {
                return new WP_Error('missing_contact_last_name', __('Lexware requires the contact person\'s last name for a company contact.', 'patsch9-accounting-bridge'), array('retryable' => false));
            }
            $company_data = array(
                'name' => $billing_company,
                'contactPersons' => array(
                    array(
                        'firstName' => (string) $order->get_billing_first_name(),
                        'lastName' => $last_name,
                        'emailAddress' => (string) $order->get_billing_email(),
                        'phoneNumber' => (string) ($order->get_billing_phone() ?: '')
                    )
                )
            );
            $tax_number = $order->get_meta('_billing_tax_number');
            if (!empty($tax_number)) {
                $company_data['taxNumber'] = (string) $tax_number;
            }
            $vat_id = $order->get_meta('_billing_vat_id');
            if (!empty($vat_id)) {
                $company_data['vatRegistrationId'] = (string) $vat_id;
                $company_data['allowTaxFreeInvoices'] = true;
            }
            $contact_data['company'] = $company_data;
        } else {
            $last_name = trim((string) $order->get_billing_last_name());
            if ('' === $last_name) {
                return new WP_Error('missing_contact_last_name', __('The last name is missing for the Lexware customer contact.', 'patsch9-accounting-bridge'), array('retryable' => false));
            }
            $contact_data['person'] = array(
                'firstName' => (string) $order->get_billing_first_name(),
                'lastName' => $last_name
            );
        }

        $country = strtoupper(trim((string) $order->get_billing_country()));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            return new WP_Error('invalid_contact_country', __('A valid two-letter country code is missing for the Lexware customer contact.', 'patsch9-accounting-bridge'), array('retryable' => false));
        }
        $contact_data['addresses'] = array(
            'billing' => array(
                array(
                    'street' => (string) $order->get_billing_address_1(),
                    'zip' => (string) $order->get_billing_postcode(),
                    'city' => (string) $order->get_billing_city(),
                    'countryCode' => $country
                )
            )
        );
        if ($order->get_billing_address_2()) {
            $contact_data['addresses']['billing'][0]['supplement'] = (string) $order->get_billing_address_2();
        }
        if ($order->get_billing_email()) {
            $contact_data['emailAddresses'] = array('business' => array((string) $order->get_billing_email()));
        }
        if ($order->get_billing_phone()) {
            $contact_data['phoneNumbers'] = array('business' => array((string) $order->get_billing_phone()));
        }

        $result = $this->request('POST', 'contacts', $contact_data);
        if (is_wp_error($result)) {
            return $result;
        }
        if (empty($result['id']) || !$this->is_valid_uuid((string) $result['id'])) {
            return new WP_Error(
                'invalid_contact_response',
                __('Lexware did not return a valid contact ID after creation. The contact may still have been created; please check in Lexware before retrying.', 'patsch9-accounting-bridge'),
                array('retryable' => false, 'ambiguous_write' => true)
            );
        }

        $contact_id = sanitize_text_field((string) $result['id']);
        $order->update_meta_data('_patsacbr_lexware_contact_id', $contact_id);
        $order->save();
        return $result;
    }

    public function create_invoice($order, $contact_id) {
        if (!$order instanceof WC_Order) {
            return new WP_Error('invalid_order', __('Invalid order', 'patsch9-accounting-bridge'));
        }
        if ('EUR' !== strtoupper((string) $order->get_currency())) {
            return new WP_Error('unsupported_currency', __('Lexware currently supports only EUR invoices through this interface.', 'patsch9-accounting-bridge'));
        }
        $existing_invoice_id = $order->get_meta('_patsacbr_lexware_invoice_id');
        if ($existing_invoice_id) {
            return array('id' => $existing_invoice_id);
        }

        $finalize   = get_option('patsacbr_finalize_immediately', 'yes') === 'yes';
        $order_date = $order->get_date_created();
        if (!$order_date) {
            return new WP_Error('missing_order_date', __('Order date missing.', 'patsch9-accounting-bridge'));
        }
        $voucher_date = $this->format_lexware_date($order_date->getTimestamp());
        // Keep the historic display preference (B2B net, B2C gross), while all
        // monetary values below are derived from WooCommerce's final line totals.
        $tax_type = !empty($order->get_billing_company()) ? 'net' : 'gross';
        $line_items = $this->format_line_items($order, false, $tax_type);
        $line_items = array_merge($line_items, $this->format_voucher_redemptions($order, $tax_type));
        if (!$line_items) {
            return new WP_Error('empty_invoice', __('The order contains no billable line items.', 'patsch9-accounting-bridge'));
        }

        $address = $this->format_address($order);
        if (empty($address['name']) || empty($address['countryCode'])) {
            return new WP_Error('invalid_address', __('The name or country of the billing address is missing for the Lexware invoice.', 'patsch9-accounting-bridge'));
        }
        if ($contact_id) {
            $address['contactId'] = sanitize_text_field((string) $contact_id);
        }

        $invoice_data = array(
            'voucherDate'        => $voucher_date,
            'address'            => $address,
            'lineItems'          => $line_items,
            // Lexware documents the calculated totals as read-only on POST.
            'totalPrice'         => array('currency' => 'EUR'),
            'taxConditions'      => array('taxType' => $tax_type),
            'shippingConditions' => array('shippingType' => 'none'),
            'title'              => $this->replace_shortcodes(get_option('patsacbr_invoice_title', __('Invoice', 'patsch9-accounting-bridge')), $order),
            'introduction'       => $this->replace_shortcodes(get_option('patsacbr_invoice_introduction', __('Thank you for your order.', 'patsch9-accounting-bridge')), $order),
            'remark'             => $this->replace_shortcodes(get_option('patsacbr_closing_text', __('Thank you for your trust.', 'patsch9-accounting-bridge')), $order),
            'paymentConditions'  => array(
                'paymentTermLabel'    => $this->replace_shortcodes($this->get_payment_terms_for_order($order), $order),
                'paymentTermDuration' => max(0, min(3650, (int) $this->get_payment_due_days_for_order($order))),
            ),
        );

        $endpoint = 'invoices' . ($finalize ? '?finalize=true' : '');
        $response = $this->request('POST', $endpoint, $invoice_data);
        if (is_wp_error($response)) {
            return $response;
        }
        if (empty($response['id']) || !$this->is_valid_uuid((string) $response['id'])) {
            return new WP_Error(
                'invalid_api_response',
                __('Lexware did not return a valid invoice ID after creation. The invoice may still have been created; please check in Lexware before retrying.', 'patsch9-accounting-bridge'),
                array('retryable' => false, 'ambiguous_write' => true)
            );
        }

        $invoice_id = sanitize_text_field((string) $response['id']);

        // Persist the remote ID before any follow-up request. If PHP is terminated
        // during voucher-number polling, later processing can still recognize the
        // already-created Lexware invoice and will not create a duplicate.
        $order->update_meta_data('_patsacbr_lexware_invoice_id', $invoice_id);
        $order->update_meta_data('_patsacbr_lexware_invoice_number', '');
        $order->save();

        $invoice_number = '';
        if ($finalize) {
            for ($i = 0; $i < 5; $i++) {
                $invoice_details = $this->request('GET', 'invoices/' . rawurlencode($invoice_id));
                if (!is_wp_error($invoice_details) && !empty($invoice_details['voucherNumber'])) {
                    $invoice_number = sanitize_text_field((string) $invoice_details['voucherNumber']);
                    break;
                }
                if ($i < 4) {
                    sleep(1);
                }
            }
        }
        if ($invoice_number) {
            $order->update_meta_data('_patsacbr_lexware_invoice_number', $invoice_number);
            $order->save();
        }
        $order->add_order_note(sprintf(
            /* translators: 1: Invoice number, 2: Invoice ID */
            __('Lexware invoice created: %1$s (ID: %2$s)', 'patsch9-accounting-bridge'),
            $invoice_number ?: __('Draft/open', 'patsch9-accounting-bridge'),
            $invoice_id
        ));
        return $response;
    }
    private function credit_note_tax_conditions_from_invoice($invoice) {
        $tax_conditions = isset($invoice['taxConditions']) && is_array($invoice['taxConditions']) ? $invoice['taxConditions'] : array();
        $tax_type = sanitize_key((string) ($tax_conditions['taxType'] ?? ''));
        $allowed = array(
            'gross',
            'net',
            'vatfree',
            'intracommunitysupply',
            'constructionservice13b',
            'externalservice13b',
            'thirdpartycountryservice',
            'thirdpartycountrydelivery',
            'photovoltaicequipment',
        );
        if (!in_array(strtolower($tax_type), $allowed, true)) {
            return new WP_Error('unsupported_credit_tax_type', __('The tax type of the original Lexware invoice cannot be safely used for a credit note.', 'patsch9-accounting-bridge'), array('retryable' => false));
        }

        // Preserve Lexware's documented camelCase enum value exactly where possible.
        $result = array('taxType' => (string) $tax_conditions['taxType']);
        if (!empty($tax_conditions['taxTypeNote'])) {
            $result['taxTypeNote'] = sanitize_text_field((string) $tax_conditions['taxTypeNote']);
        }
        return $result;
    }

    private function credit_note_address_from_invoice($invoice) {
        $source = isset($invoice['address']) && is_array($invoice['address']) ? $invoice['address'] : array();
        $address = array();
        foreach (array('name', 'supplement', 'street', 'city', 'zip') as $key) {
            if (isset($source[$key]) && '' !== trim((string) $source[$key])) {
                $address[$key] = sanitize_text_field((string) $source[$key]);
            }
        }
        $country = strtoupper(sanitize_text_field((string) ($source['countryCode'] ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $country)) {
            $address['countryCode'] = $country;
        }
        $contact_id = sanitize_text_field((string) ($source['contactId'] ?? ''));
        if ($contact_id && $this->is_valid_uuid($contact_id)) {
            $address['contactId'] = $contact_id;
        }
        if ((empty($address['name']) || empty($address['countryCode'])) && empty($address['contactId'])) {
            return new WP_Error('invalid_credit_address', __('The billing address of the original Lexware invoice cannot be safely used for the credit note.', 'patsch9-accounting-bridge'), array('retryable' => false));
        }
        return $address;
    }

    private function credit_note_line_items_from_invoice($invoice, $tax_type) {
        $source_items = isset($invoice['lineItems']) && is_array($invoice['lineItems']) ? array_slice($invoice['lineItems'], 0, 300) : array();
        $items = array();
        $use_gross = 'gross' === strtolower((string) $tax_type);

        foreach ($source_items as $source) {
            if (!is_array($source)) {
                return new WP_Error('invalid_credit_line_item', __('The original Lexware invoice contains an invalid line item.', 'patsch9-accounting-bridge'), array('retryable' => false));
            }
            $type = sanitize_key((string) ($source['type'] ?? ''));
            if (!in_array($type, array('custom', 'material', 'service', 'text'), true)) {
                return new WP_Error('unsupported_credit_line_item', __('The original Lexware invoice contains an unsupported line item type.', 'patsch9-accounting-bridge'), array('retryable' => false));
            }
            $name = sanitize_text_field((string) ($source['name'] ?? ''));
            if ('' === $name) {
                return new WP_Error('invalid_credit_line_item', __('A line item of the original Lexware invoice has no name.', 'patsch9-accounting-bridge'), array('retryable' => false));
            }

            $item = array('type' => $type, 'name' => $name);
            if (isset($source['description']) && '' !== (string) $source['description']) {
                $item['description'] = wp_kses_post((string) $source['description']);
            }
            if ('text' === $type) {
                $items[] = $item;
                continue;
            }

            if (in_array($type, array('material', 'service'), true)) {
                $id = sanitize_text_field((string) ($source['id'] ?? ''));
                if (!$this->is_valid_uuid($id)) {
                    return new WP_Error('invalid_credit_line_reference', __('A material/service line item of the original Lexware invoice has no valid reference ID.', 'patsch9-accounting-bridge'), array('retryable' => false));
                }
                $item['id'] = $id;
            }

            if (!isset($source['quantity']) || !is_numeric($source['quantity']) || 0.0 === (float) $source['quantity']) {
                return new WP_Error('invalid_credit_quantity', __('A line item of the original Lexware invoice has no valid quantity.', 'patsch9-accounting-bridge'), array('retryable' => false));
            }
            $item['quantity'] = (float) $source['quantity'];
            $item['unitName'] = sanitize_text_field((string) ($source['unitName'] ?? ''));
            if ('' === $item['unitName']) {
                return new WP_Error('invalid_credit_unit', __('A line item of the original Lexware invoice has no valid unit.', 'patsch9-accounting-bridge'), array('retryable' => false));
            }

            $source_price = isset($source['unitPrice']) && is_array($source['unitPrice']) ? $source['unitPrice'] : array();
            $currency = strtoupper((string) ($source_price['currency'] ?? ''));
            $amount_key = $use_gross ? 'grossAmount' : 'netAmount';
            if ('EUR' !== $currency || !isset($source_price[$amount_key]) || !is_numeric($source_price[$amount_key]) || !isset($source_price['taxRatePercentage']) || !is_numeric($source_price['taxRatePercentage'])) {
                return new WP_Error('invalid_credit_unit_price', __('A line item of the original Lexware invoice has no EUR unit price that can be safely used.', 'patsch9-accounting-bridge'), array('retryable' => false));
            }
            $item['unitPrice'] = array(
                'currency' => 'EUR',
                $amount_key => round((float) $source_price[$amount_key], 4),
                'taxRatePercentage' => (float) $source_price['taxRatePercentage'],
            );
            if (isset($source['discountPercentage']) && is_numeric($source['discountPercentage'])) {
                $item['discountPercentage'] = max(0.0, min(100.0, (float) $source['discountPercentage']));
            }
            $items[] = $item;
        }
        return $items;
    }

    public function create_credit_note($order, $original_invoice_id) {
        if (!$order instanceof WC_Order) {
            return new WP_Error('invalid_credit_order', __('A Lexware credit note cannot be created for this order.', 'patsch9-accounting-bridge'));
        }
        $original_invoice_id = sanitize_text_field((string) $original_invoice_id);
        if (!$this->is_valid_uuid($original_invoice_id)) {
            return new WP_Error('invalid_invoice_id', __('Invalid Lexware invoice ID.', 'patsch9-accounting-bridge'));
        }

        // An existing credit note is reusable only if it belongs to this exact
        // invoice. This prevents a second invoice correction from reusing the
        // credit note that belonged to an older invoice revision.
        $existing = sanitize_text_field((string) $order->get_meta('_patsacbr_lexware_credit_note_id'));
        $existing_for = sanitize_text_field((string) $order->get_meta('_patsacbr_lexware_credit_note_for_invoice_id'));
        if ($existing && ($existing_for === $original_invoice_id || (!$existing_for && 'yes' === $order->get_meta('_patsacbr_lexware_invoice_voided')))) {
            return array('id' => $existing);
        }

        $invoice = $this->request('GET', 'invoices/' . rawurlencode($original_invoice_id));
        if (is_wp_error($invoice)) {
            return $invoice;
        }
        if (($invoice['voucherStatus'] ?? '') === 'draft') {
            return new WP_Error(
                'draft_invoice_cannot_be_credited',
                __('The Lexware invoice is still a draft and cannot be credited as a follow-up document through the API. Please check/finalize the invoice in Lexware.', 'patsch9-accounting-bridge'),
                array('retryable' => false)
            );
        }
        // If Lexware already knows a credit note relation that is not the one
        // recorded locally, stop rather than risk a second/full duplicate credit.
        foreach ((array) ($invoice['relatedVouchers'] ?? array()) as $related) {
            if ('creditnote' === strtolower((string) ($related['voucherType'] ?? ''))) {
                $related_id = sanitize_text_field((string) ($related['id'] ?? ''));
                if (!$existing || $related_id !== $existing) {
                    return new WP_Error(
                        'existing_remote_credit_note',
                        __('A linked credit note already exists for this Lexware invoice and cannot be unambiguously assigned to this WooCommerce order. Automatic cancellation was stopped for safety reasons; please check in Lexware.', 'patsch9-accounting-bridge'),
                        array('retryable' => false)
                    );
                }
            }
        }

        // A correction must reverse the exact Lexware invoice snapshot, not the
        // current WooCommerce order. The order may have been edited since the invoice
        // was created; rebuilding from current order data could otherwise over- or
        // under-credit the original voucher.
        $tax_conditions = $this->credit_note_tax_conditions_from_invoice($invoice);
        if (is_wp_error($tax_conditions)) {
            return $tax_conditions;
        }
        $tax_type = (string) $tax_conditions['taxType'];

        $line_items = $this->credit_note_line_items_from_invoice($invoice, $tax_type);
        if (is_wp_error($line_items)) {
            return $line_items;
        }
        if (!$line_items) {
            return new WP_Error('empty_credit_note', __('The original Lexware invoice contains no line items that can be safely credited.', 'patsch9-accounting-bridge'), array('retryable' => false));
        }

        $address = $this->credit_note_address_from_invoice($invoice);
        if (is_wp_error($address)) {
            return $address;
        }

        $currency = strtoupper((string) ($invoice['totalPrice']['currency'] ?? ''));
        if ('EUR' !== $currency) {
            return new WP_Error('unsupported_credit_currency', __('The original Lexware invoice does not use the supported EUR currency.', 'patsch9-accounting-bridge'), array('retryable' => false));
        }
        $total_price = array('currency' => 'EUR');
        foreach (array('totalDiscountAbsolute', 'totalDiscountPercentage') as $discount_key) {
            if (isset($invoice['totalPrice'][$discount_key]) && is_numeric($invoice['totalPrice'][$discount_key])) {
                $total_price[$discount_key] = (float) $invoice['totalPrice'][$discount_key];
            }
        }

        $credit_note_data = array(
            'voucherDate'   => $this->format_lexware_date(time()),
            'address'       => $address,
            'lineItems'     => $line_items,
            'totalPrice'    => $total_price,
            'taxConditions' => $tax_conditions,
            'title'         => __('Credit note / cancellation', 'patsch9-accounting-bridge'),
            /* translators: %s: Original invoice number or ID */
            'introduction'  => sprintf(__('Credit note for invoice %s', 'patsch9-accounting-bridge'), sanitize_text_field((string) ($invoice['voucherNumber'] ?? $original_invoice_id))),
        );
        $endpoint = 'credit-notes?precedingSalesVoucherId=' . rawurlencode($original_invoice_id) . '&finalize=true';
        $response = $this->request('POST', $endpoint, $credit_note_data);
        if (is_wp_error($response)) {
            return $response;
        }
        if (empty($response['id']) || !$this->is_valid_uuid((string) $response['id'])) {
            return new WP_Error(
                'invalid_api_response',
                __('Lexware did not return a valid credit note ID after creation. The credit note may still have been created; please check in Lexware before retrying.', 'patsch9-accounting-bridge'),
                array('retryable' => false, 'ambiguous_write' => true)
            );
        }
        $credit_note_id = sanitize_text_field((string) $response['id']);

        // Same idempotency principle as invoices: persist the remote credit-note
        // relation before optional voucher-number polling.
        $order->update_meta_data('_patsacbr_lexware_credit_note_id', $credit_note_id);
        $order->update_meta_data('_patsacbr_lexware_credit_note_for_invoice_id', $original_invoice_id);
        $order->update_meta_data('_patsacbr_lexware_invoice_voided', 'yes');
        $order->save();

        $credit_note_number = '';
        for ($i = 0; $i < 5; $i++) {
            $details = $this->request('GET', 'credit-notes/' . rawurlencode($credit_note_id));
            if (!is_wp_error($details) && !empty($details['voucherNumber'])) {
                $credit_note_number = sanitize_text_field((string) $details['voucherNumber']);
                break;
            }
            if ($i < 4) {
                sleep(1);
            }
        }
        $order->add_order_note(sprintf(
            /* translators: 1: Credit note number, 2: Credit note ID */
            __('Lexware credit note created: %1$s (ID: %2$s)', 'patsch9-accounting-bridge'),
            $credit_note_number ?: __('open', 'patsch9-accounting-bridge'),
            $credit_note_id
        ));
        return $response;
    }

    /**
     * Download the legally relevant invoice representation to a private temp file.
     * Regular/ZUGFeRD invoices are PDF. XRechnung is XML; its Lexware PDF is only
     * a preview and must not be presented as the e-invoice.
     *
     * @return array|WP_Error {path,mime,extension,profile}
     */
    public function download_invoice_document($invoice_id) {
        $invoice_id = sanitize_text_field((string) $invoice_id);
        if (!$this->is_valid_uuid($invoice_id)) {
            return new WP_Error('invalid_invoice_id', __('Invalid Lexware invoice ID.', 'patsch9-accounting-bridge'));
        }
        $details = $this->request('GET', 'invoices/' . rawurlencode($invoice_id));
        if (is_wp_error($details)) {
            return $details;
        }
        if ('draft' === strtolower((string) ($details['voucherStatus'] ?? ''))) {
            return new WP_Error('invoice_is_draft', __('No invoice document is available for sending a Lexware invoice draft.', 'patsch9-accounting-bridge'));
        }
        $profile = strtoupper((string) ($details['electronicDocumentProfile'] ?? 'NONE'));
        $is_xrechnung = 'XRECHNUNG' === $profile;
        $accept = $is_xrechnung ? 'application/xml' : 'application/pdf';
        $binary = $this->request('GET', 'invoices/' . rawurlencode($invoice_id) . '/file', null, true, $accept);
        if (is_wp_error($binary)) {
            return $binary;
        }
        if (!is_string($binary) || strlen($binary) < 5) {
            return new WP_Error('invalid_invoice_file', __('Lexware did not return a valid invoice document.', 'patsch9-accounting-bridge'));
        }
        if ($is_xrechnung) {
            // XRechnung may use different XML syntaxes/root elements (for example UBL or CII).
            // Do not reject valid XML merely because a specific root name is absent. We only
            // perform a conservative transport-level sanity check here and leave schema
            // validation to Lexware, which generated the document.
            $trimmed = ltrim($binary, "\xEF\xBB\xBF\x00\x09\x0A\x0D\x20");
            $head = strtolower(substr($trimmed, 0, 512));
            if ('' === $trimmed || '<' !== $trimmed[0] || false !== strpos($head, '<html') || false !== strpos($head, '<!doctype html')) {
                return new WP_Error('invalid_xml', __('Lexware did not return a plausible XRechnung XML document.', 'patsch9-accounting-bridge'));
            }
            $extension = 'xml';
            $mime = 'application/xml';
        } else {
            if (0 !== strncmp($binary, '%PDF-', 5)) {
                return new WP_Error('invalid_pdf', __('Lexware did not return a valid PDF document.', 'patsch9-accounting-bridge'));
            }
            $extension = 'pdf';
            $mime = 'application/pdf';
        }

        $tmp = wp_tempnam('patsacbr-invoice.' . $extension);
        if (!$tmp) {
            return new WP_Error('temp_file', __('The temporary invoice document could not be created.', 'patsch9-accounting-bridge'));
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        global $wp_filesystem;
        if (empty($wp_filesystem) && !WP_Filesystem()) {
            wp_delete_file($tmp);
            return new WP_Error('filesystem', __('The WordPress filesystem is unavailable.', 'patsch9-accounting-bridge'));
        }
        $path = $tmp . '.' . $extension;
        if (!$wp_filesystem->put_contents($path, $binary, 0600)) {
            $wp_filesystem->delete($tmp);
            return new WP_Error('invoice_write', __('The temporary invoice document could not be written.', 'patsch9-accounting-bridge'));
        }
        $wp_filesystem->delete($tmp);
        return array('path' => $path, 'mime' => $mime, 'extension' => $extension, 'profile' => $profile);
    }

    /** Backward-compatible PDF-only helper. */
    public function download_invoice_pdf($invoice_id) {
        $document = $this->download_invoice_document($invoice_id);
        if (is_wp_error($document)) {
            return $document;
        }
        if (($document['extension'] ?? '') !== 'pdf') {
            if (!empty($document['path']) && file_exists($document['path'])) {
                wp_delete_file($document['path']);
            }
            return new WP_Error('xrechnung_requires_xml', __('This invoice is an XRechnung. The PDF rendition provided by Lexware is only a preview; the legally relevant invoice document is XML.', 'patsch9-accounting-bridge'));
        }
        return $document['path'];
    }
    private function format_address($order) {
        $company = $order->get_billing_company();

        $address = array(
            'street' => $order->get_billing_address_1(),
            'zip' => $order->get_billing_postcode(),
            'city' => $order->get_billing_city(),
            'countryCode' => $order->get_billing_country()
        );

        // Bei Firmenadressen: Firma als Hauptname, Person als Supplement
        if (!empty($company)) {
            $address['name'] = $company;
            $address['supplement'] = $order->get_formatted_billing_full_name();
        } else {
            // Bei Privatpersonen: Nur der Name
            $address['name'] = $order->get_formatted_billing_full_name();
        }

        // Adresszusatz (falls vorhanden)
        if ($order->get_billing_address_2()) {
            // Falls bereits supplement durch Firma gesetzt, anhängen
            if (isset($address['supplement'])) {
                $address['supplement'] .= ', ' . $order->get_billing_address_2();
            } else {
                $address['supplement'] = $order->get_billing_address_2();
            }
        }

        return $address;
    }
    /**
     * Berechnet den Gesamtrabatt (alle Gutscheine/Coupons zusammen, OHNE Wertgutscheine)
     * Wertgutscheine werden als separate Line Items behandelt
     *
     * @param WC_Order $order Die WooCommerce Bestellung
     * @return float Der Rabattbetrag (ohne Wertgutscheine)
     */
    private function get_total_discount($order) {
        $total_discount = 0.0;
        $coupon_codes = $order->get_coupon_codes();

        if (empty($coupon_codes)) {
            return $total_discount;
        }

        foreach ($coupon_codes as $coupon_code) {
            foreach ($order->get_items('coupon') as $coupon_item) {
                if ($coupon_item->get_code() === $coupon_code) {
                    $total_discount += abs($coupon_item->get_discount());
                    break;
                }
            }
        }

        return round($total_discount, 2);
    }
    /**
     * Berechnet Summen von Artikeln und Versand (ohne Rabatte und Wertgutscheine)
     */
    private function calculate_items_subtotal($order, $negative = false) {
        $multiplier = $negative ? -1 : 1;
        $subtotal = array(
            'net' => 0.0,
            'gross' => 0.0,
            'tax' => 0.0
        );

        // Artikel (OHNE Wertgutscheine und Fee Items)
        foreach ($order->get_items() as $item) {
            // Überspringe Wertgutschein-Items
            if ($this->is_value_voucher_item($item)) {
                continue;
            }

            // get_item_subtotal() returns the price per unit. The Lexware
            // line uses quantity separately, so the invoice subtotal must
            // multiply the per-unit subtotal by the WooCommerce quantity.
            $quantity = max(1, (float)$item->get_quantity());
            $net = round($order->get_item_subtotal($item, false) * $quantity, 2);
            $gross = round($order->get_item_subtotal($item, true) * $quantity, 2);

            $subtotal['net'] += $net * $multiplier;
            $subtotal['gross'] += $gross * $multiplier;
            $subtotal['tax'] += ($gross - $net) * $multiplier;
        }

        // Normale WooCommerce-Gebühren (z. B. Lieferung oder Mietkaution).
        // Wertgutscheine bleiben ausgeschlossen, weil sie separat verarbeitet werden.
        foreach ($order->get_items('fee') as $fee_item) {
            if ($this->is_value_voucher_item($fee_item)) {
                continue;
            }
            $net = round((float)$fee_item->get_total(), 2);
            $tax = round((float)$fee_item->get_total_tax(), 2);
            $gross = round($net + $tax, 2);
            $subtotal['net'] += $net * $multiplier;
            $subtotal['gross'] += $gross * $multiplier;
            $subtotal['tax'] += $tax * $multiplier;
        }

        // Versand
        if (get_option('patsacbr_shipping_as_line_item', 'yes') === 'yes') {
            $shipping_gross = round($order->get_shipping_total() + $order->get_shipping_tax(), 2);
            $shipping_net = round($order->get_shipping_total(), 2);

            $subtotal['net'] += $shipping_net * $multiplier;
            $subtotal['gross'] += $shipping_gross * $multiplier;
            $subtotal['tax'] += $order->get_shipping_tax() * $multiplier;
        }

        return $subtotal;
    }
    private function format_line_items($order, $negative = false, $tax_type = 'net') {
        $line_items = array();
        $tax_type = 'gross' === $tax_type ? 'gross' : 'net';
        // $negative is retained for backward-compatible filters only. Credit notes
        // are now intentionally submitted with positive quantities and values.
        unset($negative);

        foreach ($order->get_items('line_item') as $item) {
            if ($this->is_value_voucher_item($item)) {
                continue;
            }
            $quantity = max(0.0001, abs((float) $item->get_quantity()));
            $net_total = (float) $item->get_total();       // after Woo coupons/discounts
            $tax_total = (float) $item->get_total_tax();

            // WooCommerce may keep the net line amount at higher internal precision
            // while the stored tax is already rounded to cents. Adding both raw values
            // first can therefore produce visible unit prices such as 78.9966 EUR even
            // though the actual line total is exactly 79.00 EUR. Lexware supports up to
            // four decimals for unit prices, but voucher line totals are cent-based.
            // Normalize the target line amount to cents first, then derive the unit price.
            // This keeps ordinary quantities clean (e.g. 79.00) while still allowing up
            // to four decimals where a quantity genuinely requires them (e.g. 10 / 3).
            $net_line   = round($net_total, 2);
            $gross_line = round($net_total + $tax_total, 2);
            $net_unit   = round($net_line / $quantity, 4);
            $gross_unit = round($gross_line / $quantity, 4);
            $tax_rate = $this->get_tax_rate_for_class($item->get_tax_class(), $order, $item);
            $unit_price = array('currency' => 'EUR', 'taxRatePercentage' => $tax_rate);
            $unit_price['gross' === $tax_type ? 'grossAmount' : 'netAmount'] = 'gross' === $tax_type ? $gross_unit : $net_unit;
            $line_items[] = array(
                'type'      => 'custom',
                'name'      => sanitize_text_field($item->get_name()),
                'quantity'  => $quantity,
                'unitName'  => __('piece', 'patsch9-accounting-bridge'),
                'unitPrice' => $unit_price,
            );
        }

        foreach ($order->get_items('fee') as $fee_item) {
            if ($this->is_value_voucher_item($fee_item)) {
                continue;
            }
            $net = (float) $fee_item->get_total();
            $tax = (float) $fee_item->get_total_tax();
            $gross = $net + $tax;
            $is_deposit = 'yes' === $fee_item->get_meta('_clr_deposit', true) || 'deposit' === $fee_item->get_meta('_clr_fee_type', true);

            // A genuine refundable rental deposit is a security payment, not consideration
            // for the rental service. Do not turn it into a 0% revenue line in Lexware.
            // Instead, show it as an informational text line so the invoice remains clear
            // while the deposit does not change the Lexware invoice total.
            if ($is_deposit) {
                $amount = abs($gross);
                $formatted_amount = number_format($amount, 2, ',', '.') . ' EUR';
                $line_items[] = array(
                    'type'        => 'text',
                    'name'        => __('Deposit – refundable security deposit', 'patsch9-accounting-bridge'),
                    'description' => sprintf(
                        /* translators: %s: deposit amount */
                        __('Deposit collected in addition to the invoice total: %s. The deposit is not consideration for the rental and will be refunded unless there is damage, loss, or a cleaning charge payable under the contract.', 'patsch9-accounting-bridge'),
                        $formatted_amount
                    ),
                );
                continue;
            }

            $tax_rate = $this->get_tax_rate_for_class($fee_item->get_tax_class(), $order, $fee_item);
            $unit_price = array('currency' => 'EUR', 'taxRatePercentage' => $tax_rate);
            $unit_price['gross' === $tax_type ? 'grossAmount' : 'netAmount'] = round('gross' === $tax_type ? round($gross, 2) : round($net, 2), 2);
            $line_items[] = array(
                'type'      => 'custom',
                'name'      => sanitize_text_field($fee_item->get_name()),
                'quantity'  => 1,
                'unitName'  => __('flat rate', 'patsch9-accounting-bridge'),
                'unitPrice' => $unit_price,
            );
        }

        if ('yes' === get_option('patsacbr_shipping_as_line_item', 'yes') && ((float) $order->get_shipping_total() != 0.0 || (float) $order->get_shipping_tax() != 0.0)) {
            $shipping_net = (float) $order->get_shipping_total();
            $shipping_gross = $shipping_net + (float) $order->get_shipping_tax();
            $unit_price = array('currency' => 'EUR', 'taxRatePercentage' => $this->calculate_shipping_tax_rate($order));
            $unit_price['gross' === $tax_type ? 'grossAmount' : 'netAmount'] = round('gross' === $tax_type ? $shipping_gross : $shipping_net, 2);
            $line_items[] = array(
                'type'      => 'custom',
                'name'      => __('Shipping costs', 'patsch9-accounting-bridge'),
                'quantity'  => 1,
                'unitName'  => __('flat rate', 'patsch9-accounting-bridge'),
                'unitPrice' => $unit_price,
            );
        }
        return apply_filters('patsacbr_formatted_line_items', $line_items, $order, false);
    }

    private function format_voucher_redemptions($order, $tax_type) {
        $items = array();
        foreach ($this->get_voucher_items_from_order($order) as $voucher) {
            $amount = max(0, (float) $voucher['amount']);
            if ($amount <= 0) {
                continue;
            }
            $unit_price = array('currency' => 'EUR', 'taxRatePercentage' => 0.0);
            $unit_price['gross' === $tax_type ? 'grossAmount' : 'netAmount'] = -round($amount, 2);
            $items[] = array(
                'type'      => 'custom',
                'name'      => __('Gift voucher redemption', 'patsch9-accounting-bridge'),
                'quantity'  => 1,
                'unitName'  => __('flat rate', 'patsch9-accounting-bridge'),
                'unitPrice' => $unit_price,
            );
        }
        return $items;
    }
    private function get_tax_rate_for_class($tax_class, $order, $item = null) {
        unset($tax_class, $order);
        if ($item && is_callable(array($item, 'get_meta')) && ('yes' === $item->get_meta('_clr_deposit', true) || 'deposit' === $item->get_meta('_clr_fee_type', true))) {
            return 0.0;
        }
        if ($item && is_callable(array($item, 'get_tax_status')) && 'taxable' !== $item->get_tax_status()) {
            return 0.0;
        }
        if ($item && is_callable(array($item, 'get_taxes'))) {
            $taxes = $item->get_taxes();
            foreach (array('total', 'subtotal') as $bucket) {
                if (!empty($taxes[$bucket]) && is_array($taxes[$bucket])) {
                    foreach (array_keys($taxes[$bucket]) as $tax_id) {
                        if (absint($tax_id) > 0 && class_exists('WC_Tax')) {
                            return (float) WC_Tax::get_rate_percent_value(absint($tax_id));
                        }
                    }
                }
            }
        }
        if ($item && is_callable(array($item, 'get_total_tax')) && abs((float) $item->get_total_tax()) < 0.00001) {
            return 0.0;
        }
        return 0.0;
    }
    private function get_average_tax_rate_for_items($order) {
        // Berechne den durchschnittlichen Steuersatz basierend auf allen Artikeln
        if ($order->get_subtotal() > 0) {
            $total_tax = $order->get_total_tax();
            $items_subtotal = $order->get_subtotal();
            if ($items_subtotal > 0 && $total_tax > 0) {
                $rate = ($total_tax / $items_subtotal) * 100;
                return round($rate, 2);
            }
        }
        return 19.0; // Fallback auf 19%
    }
    private function calculate_shipping_tax_rate($order) {
        foreach ($order->get_items('tax') as $tax_item) {
            if ((float) $tax_item->get_shipping_tax_total() != 0.0 && is_callable(array($tax_item, 'get_rate_percent'))) {
                return (float) $tax_item->get_rate_percent();
            }
        }
        if (abs((float) $order->get_shipping_tax()) < 0.00001) {
            return 0.0;
        }
        $net = (float) $order->get_shipping_total();
        return $net != 0.0 ? round(((float) $order->get_shipping_tax() / $net) * 100, 2) : 0.0;
    }
    private function get_payment_terms_for_order($order) {
        $payment_method = $order->get_payment_method();
        $specific_terms = get_option('patsacbr_payment_terms_' . $payment_method, '');
        $terms = !empty($specific_terms)
            ? (string) $specific_terms
            : (string) get_option('patsacbr_payment_terms', __('Payable within 14 days without deduction.', 'patsch9-accounting-bridge'));

        // Optional extension metadata. No dependency on the rental plugin:
        // normal WooCommerce orders simply do not have this value.
        $addition = $order instanceof WC_Order
            ? (string) $order->get_meta('_clr_invoice_payment_terms_addition', true)
            : '';
        if ('' !== $addition) {
            // Older rental-plugin builds stored wc_price() output after stripping
            // HTML tags, which could leave entities such as &nbsp; in plain text.
            $addition = html_entity_decode(wp_strip_all_tags($addition), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
            $addition = str_replace("\xc2\xa0", ' ', $addition);
            $addition = sanitize_textarea_field($addition);
        }

        /**
         * Optional integration point for rental/accounting extensions.
         * Standalone WooCommerce orders remain unchanged.
         */
        $addition = apply_filters('patsacbr_payment_terms_addition', $addition, $order);
        $addition = sanitize_textarea_field((string) $addition);

        if ('' !== trim($addition)) {
            $terms = rtrim($terms);
            if ('' !== $terms) {
                $terms .= "\n\n";
            }
            $terms .= $addition;
        }
        return $terms;
    }
    private function get_payment_due_days_for_order($order) {
        $payment_method = $order->get_payment_method();
        $specific_days = get_option('patsacbr_payment_due_days_' . $payment_method, '');
        if ($specific_days !== '' && $specific_days !== false) {
            return (int) $specific_days;
        }
        return (int) get_option('patsacbr_payment_due_days', 14);
    }
    public function replace_shortcodes($text, $order) {
        if (!$order) return $text;

        // Formatiere Preis ohne HTML
        $total_formatted = number_format_i18n($order->get_total(), 2) . ' ' . $order->get_currency();

        $replace = array(
            '[order_number]'     => $order->get_order_number(),
            '[order_date]'       => date_i18n(get_option('date_format'), strtotime($order->get_date_created())),
            '[customer_name]'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            '[customer_company]' => $order->get_billing_company(),
            '[total]'            => $total_formatted,
            '[payment_method]'   => $order->get_payment_method_title(),
        );

        return strtr($text, $replace);
    }
    /**
 * Returns whether a usable Lexware API key is configured.
 *
 * @return bool
 */
public function is_configured() {
    return '' !== $this->api_key;
}

/**
 * Normalize Lexware formatted text for deterministic matching.
 *
 * @param string $text Source text.
 * @return string
 */
private function normalize_reconciliation_text($text) {
    $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return is_string($text) ? trim($text) : '';
}

/**
 * Check whether the complete WooCommerce order number occurs in the
 * invoice introduction. Unicode letter/number boundaries prevent order
 * 123 from matching 1123 or 1234.
 *
 * @param string $introduction Lexware invoice introduction.
 * @param string $order_number WooCommerce order number.
 * @return bool
 */
private function introduction_contains_order_number($introduction, $order_number) {
    $introduction = $this->normalize_reconciliation_text($introduction);
    $order_number = $this->normalize_reconciliation_text((string) $order_number);
    if ('' === $introduction || '' === $order_number || strlen($order_number) > 191) {
        return false;
    }

    $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($order_number, '/') . '(?![\p{L}\p{N}])/u';
    return 1 === preg_match($pattern, $introduction);
}

/**
 * Find an already existing Lexware invoice for a WooCommerce order.
 *
 * The lookup is intentionally read-only and fail-closed. Voucherlist is
 * used for paging and amount preselection; full invoice details are then
 * retrieved because Lexware does not expose the introduction as a
 * voucherlist filter.
 *
 * @param WC_Order $order WooCommerce order.
 * @param int      $cutoff_timestamp Upper date boundary for historical reconciliation.
 * @return array|null|WP_Error Full invoice data, null for a confirmed non-match, or error.
 */
public function find_invoice_by_order($order, $cutoff_timestamp = 0) {
    if (!$order instanceof WC_Order) {
        return new WP_Error('invalid_order', __('Invalid order for the Lexware invoice search.', 'patsch9-accounting-bridge'), array('retryable' => false));
    }
    if (!$this->is_configured()) {
        return new WP_Error('no_api_key', __('No valid API key configured', 'patsch9-accounting-bridge'), array('retryable' => false));
    }

    $created = $order->get_date_created();
    $order_number = trim((string) $order->get_order_number());
    if (!$created || '' === $order_number) {
        return new WP_Error('missing_order_reference', __('The order has no usable order number or creation date.', 'patsch9-accounting-bridge'), array('retryable' => false));
    }

    $order_total = round((float) $order->get_total(), 2);
    $before_days = max(0, min(31, absint(apply_filters('patsacbr_reconciliation_days_before_order', 7, $order))));
    $after_days = max(1, min(730, absint(apply_filters('patsacbr_reconciliation_days_after_order', 180, $order))));
    $from_ts = $created->getTimestamp() - ($before_days * DAY_IN_SECONDS);
    $to_ts = $created->getTimestamp() + ($after_days * DAY_IN_SECONDS);
    if ($cutoff_timestamp > 0) {
        $to_ts = min($to_ts, absint($cutoff_timestamp));
    }
    if ($to_ts < $from_ts) {
        $to_ts = $from_ts;
    }

    $from = wp_date('Y-m-d', $from_ts, wp_timezone());
    $to = wp_date('Y-m-d', $to_ts, wp_timezone());
    $matches = array();
    $page = 0;
    $max_pages = 20;

    do {
        $query = http_build_query(array(
            'voucherType' => 'invoice',
            'voucherStatus' => 'any',
            'voucherDateFrom' => $from,
            'voucherDateTo' => $to,
            'size' => 250,
            'page' => $page,
            'sort' => 'voucherDate,ASC',
        ), '', '&', PHP_QUERY_RFC3986);
        $list = $this->request('GET', 'voucherlist?' . $query);
        if (is_wp_error($list)) {
            return $list;
        }
        if (!isset($list['content']) || !is_array($list['content'])) {
            return new WP_Error('invalid_voucherlist_response', __('Lexware did not return a valid document list during the historical invoice search.', 'patsch9-accounting-bridge'), array('retryable' => false, 'manual_check' => true));
        }

        foreach ($list['content'] as $candidate) {
            if (!is_array($candidate) || 'invoice' !== strtolower((string) ($candidate['voucherType'] ?? ''))) {
                continue;
            }
            if (!isset($candidate['totalAmount']) || abs(round((float) $candidate['totalAmount'], 2) - $order_total) > 0.02) {
                continue;
            }

            $invoice_id = sanitize_text_field((string) ($candidate['id'] ?? ''));
            if (!$this->is_valid_uuid($invoice_id)) {
                return new WP_Error('invalid_invoice_search_id', __('Lexware returned an invalid invoice ID during the historical invoice search.', 'patsch9-accounting-bridge'), array('retryable' => false, 'manual_check' => true));
            }

            $details = $this->request('GET', 'invoices/' . rawurlencode($invoice_id));
            if (is_wp_error($details)) {
                return $details;
            }
            if (!isset($details['totalPrice']['totalGrossAmount'])) {
                return new WP_Error('invalid_invoice_search_response', __('Lexware returned incomplete invoice data during the historical invoice search.', 'patsch9-accounting-bridge'), array('retryable' => false, 'manual_check' => true));
            }
            if (abs(round((float) $details['totalPrice']['totalGrossAmount'], 2) - $order_total) > 0.02) {
                continue;
            }
            if (!$this->introduction_contains_order_number((string) ($details['introduction'] ?? ''), $order_number)) {
                continue;
            }

            $details_id = sanitize_text_field((string) ($details['id'] ?? ''));
            if (!$this->is_valid_uuid($details_id) || $details_id !== $invoice_id) {
                return new WP_Error('invoice_identity_mismatch', __('Lexware returned inconsistent invoice data. The order was not linked automatically.', 'patsch9-accounting-bridge'), array('retryable' => false, 'manual_check' => true));
            }
            $matches[$invoice_id] = $details;
        }

        $total_pages = isset($list['totalPages']) ? absint($list['totalPages']) : 1;
        if ($total_pages > $max_pages) {
            return new WP_Error('invoice_search_too_broad', __('The Lexware search period contains too many invoices for safe automatic reconciliation. Please review the order manually or narrow the search period using a filter.', 'patsch9-accounting-bridge'), array('retryable' => false, 'manual_check' => true));
        }
        $page++;
    } while ($page < $total_pages);

    if (!$matches) {
        return null;
    }

    $usable = array();
    $voided = array();
    foreach ($matches as $invoice_id => $invoice) {
        $status = strtolower((string) ($invoice['voucherStatus'] ?? ''));
        if ('draft' === $status) {
            return new WP_Error('draft_invoice_match', __('A matching invoice draft was found in Lexware for this order. Manual review is required to prevent duplicate documents.', 'patsch9-accounting-bridge'), array('retryable' => false, 'manual_check' => true));
        }
        if ('voided' === $status) {
            $voided[$invoice_id] = $invoice;
        } else {
            $usable[$invoice_id] = $invoice;
        }
    }

    if (1 === count($usable) && count($matches) === (1 + count($voided))) {
        return reset($usable);
    }
    if (0 === count($usable) && 1 === count($voided)) {
        return reset($voided);
    }
    if (1 === count($matches)) {
        return reset($matches);
    }

    return new WP_Error('ambiguous_invoice_match', __('Multiple invoices matching this order number and total amount were found in Lexware. No automatic link was created.', 'patsch9-accounting-bridge'), array('retryable' => false, 'manual_check' => true));
}

    private function request($method, $endpoint, $data = null, $raw_response = false, $accept = null) {
        if (empty($this->api_key)) {
            return new WP_Error('no_api_key', __('No valid API key configured', 'patsch9-accounting-bridge'));
        }
        $method = strtoupper((string) $method);
        if (!in_array($method, array('GET', 'POST', 'PUT', 'DELETE'), true)) {
            return new WP_Error('invalid_method', __('Invalid API method', 'patsch9-accounting-bridge'));
        }
        $endpoint = ltrim((string) $endpoint, '/');
        // Endpoint is constructed internally only. Reject schemes, traversal and control characters.
        if ('' === $endpoint || false !== strpos($endpoint, '..') || preg_match('#^[a-z][a-z0-9+.-]*://#i', $endpoint) || preg_match('/[\x00-\x1F\x7F]/', $endpoint)) {
            return new WP_Error('invalid_endpoint', __('Invalid API endpoint', 'patsch9-accounting-bridge'));
        }

        $this->enforce_rate_limit();
        $url = self::API_BASE_URL . $endpoint;
        $args = array(
            'method'              => $method,
            'headers'             => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => $accept ? sanitize_mime_type((string) $accept) : ($raw_response ? 'application/pdf' : 'application/json'),
            ),
            'timeout'             => $raw_response ? 45 : 30,
            'redirection'         => 0,
            'limit_response_size' => $raw_response ? 20 * MB_IN_BYTES : 2 * MB_IN_BYTES,
        );
        if (null !== $data && in_array($method, array('POST', 'PUT'), true)) {
            $encoded = wp_json_encode($data);
            if (false === $encoded) {
                return new WP_Error('json_encode', __('API data could not be serialized.', 'patsch9-accounting-bridge'));
            }
            $args['body'] = $encoded;
        }

        $retry_count = 0;
        do {
            $response = wp_safe_remote_request($url, $args);
            if (is_wp_error($response)) {
                $retryable = 'POST' !== $method;
                $this->log_error(__('API transport error', 'patsch9-accounting-bridge'), $response->get_error_message(), array('endpoint' => $endpoint, 'method' => $method));
                $transport_message = __('The connection to the Lexware API failed.', 'patsch9-accounting-bridge');
                if ('POST' === $method) {
                    $transport_message .= ' ' . __('The write operation will not be retried automatically because it may already have completed on the server. Please check in Lexware.', 'patsch9-accounting-bridge');
                }
                return new WP_Error(
                    'patsacbr_api_transport',
                    $transport_message,
                    array('retryable' => $retryable, 'ambiguous_write' => ('POST' === $method))
                );
            }
            $status = (int) wp_remote_retrieve_response_code($response);
            if (429 === $status && $retry_count < self::MAX_RETRIES) {
                $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');
                $wait = $retry_after > 0 && $retry_after <= 30 ? $retry_after : min(8, 2 ** $retry_count);
                sleep(max(1, $wait));
                $retry_count++;
                continue;
            }
            break;
        } while (true);

        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);
        if ('yes' === get_option('patsacbr_enable_logging', 'no')) {
            $this->log_request($method, $endpoint, $data, $status, $body);
        }
        if ($status < 200 || $status >= 300) {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && !empty($decoded['message'])) {
                $message = sanitize_text_field((string) $decoded['message']);
            } else {
                /* translators: %d: HTTP status code returned by the Lexware Office API. */
                $message = sprintf(__('Lexware API error (HTTP %d)', 'patsch9-accounting-bridge'), $status);
            }
            $message = function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500);
            // A 429 explicitly means the write was rejected and is safe to retry.
            // POST transport/5xx errors are intentionally not auto-retried because
            // the remote side may already have created a voucher.
            $retryable = 429 === $status || ('POST' !== $method && $status >= 500 && $status <= 599);
            if ('POST' === $method && $status >= 500) {
                $message .= ' ' . __('The write operation will not be retried automatically because Lexware may already have processed it despite the error. Please check in Lexware before sending again.', 'patsch9-accounting-bridge');
            }
            $this->log_error(__('API error', 'patsch9-accounting-bridge'), $message, array('status' => $status, 'endpoint' => $endpoint, 'method' => $method));
            return new WP_Error('patsacbr_api_http', $message, array('status' => $status, 'retryable' => $retryable, 'ambiguous_write' => ('POST' === $method && $status >= 500)));
        }
        if ($raw_response) {
            return $body;
        }
        if ('' === $body) {
            if ('POST' === $method) {
                return new WP_Error(
                    'patsacbr_api_ambiguous_response',
                    __('Lexware confirmed the write operation succeeded but did not return a usable response body. The record may already have been created; please check in Lexware before retrying.', 'patsch9-accounting-bridge'),
                    array('status' => $status, 'retryable' => false, 'ambiguous_write' => true)
                );
            }
            return array();
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return new WP_Error(
                'invalid_json',
                'POST' === $method
                    ? __('Lexware returned an invalid JSON response after a write operation. The record may already have been created; please check in Lexware before retrying.', 'patsch9-accounting-bridge')
                    : __('Lexware returned an invalid JSON response.', 'patsch9-accounting-bridge'),
                array('status' => $status, 'retryable' => false, 'ambiguous_write' => ('POST' === $method))
            );
        }
        return $decoded;
    }
    private function redact_log_data($data, $depth = 0) {
        if ($depth > 8) {
            return '[depth-limit]';
        }
        if (!is_array($data)) {
            return is_scalar($data) || $data === null ? $data : '[object]';
        }
        $redacted = array();
        $sensitive_keys = array('email', 'phone', 'street', 'zip', 'city', 'name', 'supplement', 'address', 'request_data', 'api_key', 'authorization', 'x-goog-api-key');
        foreach ($data as $key => $value) {
            $normalized = strtolower((string)$key);
            if (in_array($normalized, $sensitive_keys, true)) {
                $redacted[$key] = '[redacted]';
                continue;
            }
            $redacted[$key] = is_array($value) ? $this->redact_log_data($value, $depth + 1) : $value;
        }
        return $redacted;
    }

    private function redact_log_string($value) {
        $value = sanitize_textarea_field((string)$value);
        $value = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $value);
        $value = preg_replace('/(?<!\d)(?:\+?\d[\d\s().\/-]{6,}\d)(?!\d)/', '[redacted-number]', $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, 500) : substr($value, 0, 500);
    }

    private function log_request($method, $endpoint, $data, $status, $response) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'method' => $method,
            'endpoint' => $endpoint,
            'request_data' => $this->redact_log_data($data),
            'status_code' => $status,
            'response' => ($status >= 400 ? '[error response body omitted to protect personal data]' : '[successful response omitted]')
        );
        $logs = get_option('patsacbr_api_logs', array());
        array_unshift($logs, $log_entry);
        $logs = array_slice($logs, 0, 100);
        update_option('patsacbr_api_logs', $logs);
    }
    private function log_error($title, $message, $context = array()) {
        $context = $this->redact_log_data($context);
        $error_entry = array(
            'timestamp' => current_time('mysql'),
            'title' => $this->redact_log_string($title),
            'message' => $this->redact_log_string($message),
            'context' => $context
        );
        $errors = get_option('patsacbr_error_logs', array());
        array_unshift($errors, $error_entry);
        $errors = array_slice($errors, 0, 50);
        update_option('patsacbr_error_logs', $errors);
        if (get_option('patsacbr_email_on_error', 'yes') === 'yes') {
            $admin_email = get_option('admin_email');
            if (is_email($admin_email)) {
                wp_mail(
                    $admin_email,
                    __('[Patsch9 Accounting Bridge] Error', 'patsch9-accounting-bridge'),
                    sprintf(
                        /* translators: 1: Error title, 2: Error message, 3: Local timestamp. */
                        __('Error: %1$s' . "\n\n" . 'Message: %2$s' . "\n\n" . 'Time: %3$s', 'patsch9-accounting-bridge'),
                        $this->redact_log_string($title),
                        $this->redact_log_string($message),
                        current_time('mysql')
                    )
                );
            }
        }
    }
}
