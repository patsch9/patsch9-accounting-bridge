#!/usr/bin/env python3
from pathlib import Path
import re

api = Path('includes/class-lexware-api-client.php')
text = api.read_text(encoding='utf-8')


def replace_once(old, new, label):
    global text
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected exactly one match, found {count}')
    text = text.replace(old, new, 1)


replace_once(
    "    private $last_request_time = 0;\n",
    "",
    'unused last_request_time property',
)

marker = "    public function create_invoice($order, $contact_id) {\n"
helper = """    /** Determine Lexware's price mode from the order snapshot, not customer type. */
    private function invoice_tax_type($order) {
        if ($order && is_callable(array($order, 'get_prices_include_tax'))) {
            return $order->get_prices_include_tax() ? 'gross' : 'net';
        }
        // Compatibility fallback for lightweight integrations/mocks. A real
        // WC_Order exposes get_prices_include_tax(), preserving historical orders.
        if (function_exists('wc_prices_include_tax')) {
            return wc_prices_include_tax() ? 'gross' : 'net';
        }
        return 'gross';
    }

"""
replace_once(marker, helper + marker, 'invoice tax-type helper insertion')

replace_once(
    "        // Keep the historic display preference (B2B net, B2C gross), while all\n"
    "        // monetary values below are derived from WooCommerce's final line totals.\n"
    "        $tax_type = !empty($order->get_billing_company()) ? 'net' : 'gross';\n",
    "        // Preserve the pricing mode stored on this exact WooCommerce order.\n"
    "        // A company name does not imply net pricing and must not alter rounding.\n"
    "        $tax_type = $this->invoice_tax_type($order);\n",
    'invoice tax-type selection',
)

temp_marker = "    public function download_invoice_document($invoice_id) {\n"
temp_helper = r'''    /**
     * Write a private temporary invoice document atomically with its final extension.
     *
     * @param string $binary    Document bytes.
     * @param string $extension pdf or xml.
     * @return string|WP_Error
     */
    private function write_temporary_invoice_document($binary, $extension) {
        $extension = strtolower((string) $extension);
        if (!in_array($extension, array('pdf', 'xml'), true)) {
            return new WP_Error('temp_file', __('The temporary invoice document could not be created.', 'patsch9-accounting-bridge'));
        }
        $bytes = (string) $binary;
        $length = strlen($bytes);
        if ($length < 5 || $length > 20 * MB_IN_BYTES) {
            return new WP_Error('temp_file', __('The temporary invoice document could not be created.', 'patsch9-accounting-bridge'));
        }

        $temp_dir = trailingslashit(get_temp_dir());
        $system_temp = function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Prefer the OS temp directory for short-lived, permission-restricted attachments outside potentially web-accessible WordPress paths.
        if (is_string($system_temp) && '' !== $system_temp && is_dir($system_temp) && is_writable($system_temp)) {
            $temp_dir = trailingslashit($system_temp);
        }

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $suffix = strtolower(wp_generate_password(16, false, false));
            $path = $temp_dir . 'patsacbr-invoice-' . $suffix . '.' . $extension;
            $old_umask = umask(0077);
            $handle = @fopen($path, 'xb'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive transient file; restrictive umask is set before creation.
            umask($old_umask);
            if (false === $handle) {
                continue;
            }
            @chmod($path, 0600); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Defense in depth after restrictive creation mode.

            $offset = 0;
            $write_ok = true;
            while ($offset < $length) {
                $written = fwrite($handle, substr($bytes, $offset)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Generated transient attachment in OS temp directory.
                if (false === $written || 0 === $written) {
                    $write_ok = false;
                    break;
                }
                $offset += $written;
            }
            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            if (!$write_ok || $offset !== $length) {
                @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleanup of the transient file created above.
                continue;
            }
            return $path;
        }

        return new WP_Error('temp_file', __('The temporary invoice document could not be created.', 'patsch9-accounting-bridge'));
    }

'''
replace_once(temp_marker, temp_helper + temp_marker, 'temporary document helper insertion')

old_temp = r'''        $tmp = wp_tempnam('patsacbr-invoice.' . $extension);
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
'''
new_temp = r'''        $path = $this->write_temporary_invoice_document($binary, $extension);
        if (is_wp_error($path)) {
            return $path;
        }
        return array('path' => $path, 'mime' => $mime, 'extension' => $extension, 'profile' => $profile);
'''
replace_once(old_temp, new_temp, 'temporary document write path')

tax_pattern = re.compile(
    r"    private function get_tax_rate_for_class\(\$tax_class, \$order, \$item = null\) \{.*?\n    \}\n    private function get_average_tax_rate_for_items\(\$order\) \{.*?\n    \}\n    private function calculate_shipping_tax_rate",
    re.S,
)
tax_replacement = r'''    private function get_tax_rate_for_class($tax_class, $order, $item = null) {
        unset($tax_class);
        if ($this->is_rental_deposit($item)) {
            return 0.0;
        }
        if ($item && is_callable(array($item, 'get_tax_status')) && 'taxable' !== $item->get_tax_status()) {
            return 0.0;
        }

        $tax_ids = array();
        if ($item && is_callable(array($item, 'get_taxes'))) {
            $taxes = $item->get_taxes();
            foreach (array('total', 'subtotal') as $bucket) {
                if (empty($taxes[$bucket]) || !is_array($taxes[$bucket])) {
                    continue;
                }
                foreach (array_keys($taxes[$bucket]) as $tax_id) {
                    $tax_id = absint($tax_id);
                    if ($tax_id > 0) {
                        $tax_ids[$tax_id] = true;
                    }
                }
            }
        }
        $tax_ids = array_keys($tax_ids);

        // Order tax items preserve the historical percentage even when the live
        // WooCommerce tax rule was deleted or changed after the order was placed.
        if ($tax_ids && $order && is_callable(array($order, 'get_items'))) {
            foreach ($order->get_items('tax') as $tax_item) {
                if (!is_callable(array($tax_item, 'get_rate_id')) || !is_callable(array($tax_item, 'get_rate_percent'))) {
                    continue;
                }
                $stored_rate_id = absint($tax_item->get_rate_id());
                if (!in_array($stored_rate_id, $tax_ids, true)) {
                    continue;
                }
                $stored_rate = (float) $tax_item->get_rate_percent();
                if ($stored_rate > 0.0) {
                    return $stored_rate;
                }
            }
        }

        // Current WooCommerce rates remain a valid secondary source. Do not return
        // zero here: a deleted rate resolves to 0/empty and would suppress the
        // amount-based historical fallback below.
        if ($tax_ids && class_exists('WC_Tax')) {
            foreach ($tax_ids as $tax_id) {
                $rate = (float) WC_Tax::get_rate_percent_value($tax_id);
                if ($rate > 0.0) {
                    return $rate;
                }
            }
        }

        if ($item && is_callable(array($item, 'get_total_tax')) && is_callable(array($item, 'get_total'))) {
            $net = (float) $item->get_total();
            $tax = (float) $item->get_total_tax();
            if (abs($tax) < 0.00001) {
                return 0.0;
            }
            if (abs($net) > 0.00001) {
                return round(abs($tax / $net) * 100, 2);
            }
        }
        return 0.0;
    }
    private function calculate_shipping_tax_rate'''
text, count = tax_pattern.subn(tax_replacement, text, count=1)
if count != 1:
    raise SystemExit(f'tax-rate method replacement: expected 1 match, found {count}')

# Remove two remaining unused legacy calculation methods.
for name, next_name in [
    ('get_total_discount', 'calculate_items_subtotal'),
    ('calculate_items_subtotal', 'format_line_items'),
]:
    pattern = re.compile(
        rf"    /\*\*.*?\*/\n    private function {name}\(.*?\n    \}}\n(?=    (?:/\*\*.*?\*/\n    )?private function {next_name}\()",
        re.S,
    )
    text, count = pattern.subn('', text, count=1)
    if count != 1:
        raise SystemExit(f'legacy method removal {name}: expected 1 match, found {count}')

api.write_text(text, encoding='utf-8')

test = Path('tests/test-rental-meta.php')
test_text = test.read_text(encoding='utf-8')
needle = "function wc_prices_include_tax() { return false; }\n"
if needle not in test_text:
    raise SystemExit('test absint insertion anchor missing')
test_text = test_text.replace(needle, needle + "function absint( $value ) { return abs( (int) $value ); }\n", 1)
test.write_text(test_text, encoding='utf-8')

main = Path('patsch9-accounting-bridge.php')
main_text = main.read_text(encoding='utf-8')
if 'Version: 2026.10.7' not in main_text or "define('PATSACBR_VERSION', '2026.10.7');" not in main_text:
    raise SystemExit('main plugin version anchors missing')
main_text = main_text.replace('Version: 2026.10.7', 'Version: 2026.10.8', 1)
main_text = main_text.replace("define('PATSACBR_VERSION', '2026.10.7');", "define('PATSACBR_VERSION', '2026.10.8');", 1)
main.write_text(main_text, encoding='utf-8')

readme_md = Path('README.md')
md = readme_md.read_text(encoding='utf-8')
if 'Entwicklungsstand: **2026.10.7**' not in md:
    raise SystemExit('README development version anchor missing')
md = md.replace('Entwicklungsstand: **2026.10.7**', 'Entwicklungsstand: **2026.10.8**', 1)
readme_md.write_text(md, encoding='utf-8')

readme = Path('readme.txt')
rt = readme.read_text(encoding='utf-8')
if 'Stable tag: 2026.10.7' not in rt:
    raise SystemExit('readme stable tag anchor missing')
rt = rt.replace('Stable tag: 2026.10.7', 'Stable tag: 2026.10.8', 1)
changelog_anchor = '== Changelog ==\n\n'
if changelog_anchor not in rt:
    raise SystemExit('readme changelog anchor missing')
entry = (
    '= 2026.10.8 =\n'
    '* Preserve historical WooCommerce tax percentages when live tax rules were deleted or changed, with a safe amount-based fallback including negative taxable fees.\n'
    '* Derive Lexware gross/net invoice mode from the order snapshot instead of the presence of a company name.\n'
    '* Create PDF/XML invoice attachments atomically with restrictive permissions and their final file extension.\n'
    '* Remove unused legacy invoice-calculation helpers.\n\n'
)
rt = rt.replace(changelog_anchor, changelog_anchor + entry, 1)
readme.write_text(rt, encoding='utf-8')

changelog = Path('CHANGELOG.md')
cl = changelog.read_text(encoding='utf-8')
anchor = 'Alle stabilen Releases ab Oktober 2026 verwenden das in [VERSIONING.md](VERSIONING.md) dokumentierte Schema `YYYY.M.PATCH`.\n\n'
if anchor not in cl:
    raise SystemExit('CHANGELOG anchor missing')
cl_entry = (
    '## 2026.10.8 – Noch nicht veröffentlicht\n\n'
    '- Historische Umsatzsteuersätze werden zuerst aus den Steuerpositionen der Bestellung gelesen; gelöschte aktuelle WooCommerce-Steuerregeln können dadurch keine besteuerten Positionen mehr fälschlich auf 0 % setzen.\n'
    '- Falls weder historische noch aktuelle Steuerdaten den Satz liefern, wird er aus den gespeicherten Netto- und Steuerbeträgen berechnet; auch negative steuerpflichtige Gebühren werden korrekt behandelt.\n'
    '- Lexware erhält `gross`/`net` anhand der in der Bestellung gespeicherten WooCommerce-Preisdarstellung statt anhand eines ausgefüllten Firmenfelds.\n'
    '- Temporäre PDF-/XML-Belege werden exklusiv und atomar mit finaler Dateiendung und restriktiven Dateirechten erzeugt.\n'
    '- Nicht mehr verwendete Berechnungsmethoden und die ungenutzte Rate-Limit-Property wurden entfernt.\n\n'
)
cl = cl.replace(anchor, anchor + cl_entry, 1)
changelog.write_text(cl, encoding='utf-8')
