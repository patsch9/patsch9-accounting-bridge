<?php
/** Actual export methods with minimal order doubles; no API requests. */
define( 'ABSPATH', __DIR__ );
function __( $value, $domain = '' ) { return $value; }
function get_option( $key, $default = false ) { return $default; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function sanitize_textarea_field( $value ) { return strip_tags( $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function get_bloginfo( $key ) { return 'UTF-8'; }
function wc_prices_include_tax() { return false; }
function get_temp_dir() { return rtrim( sys_get_temp_dir(), '/\\' ) . DIRECTORY_SEPARATOR; }
function trailingslashit( $value ) { return rtrim( (string) $value, '/\\' ) . DIRECTORY_SEPARATOR; }
function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
    return substr( bin2hex( random_bytes( max( 8, $length ) ) ), 0, $length );
}
class WC_Tax {
    public static array $rates = [];
    public static function get_rate_percent_value( $tax_id ) { return (float) ( self::$rates[ (int) $tax_id ] ?? 0.0 ); }
}
class PATSACBR_Test_Fee {
    public function __construct( private array $meta, private float $total = 150.0, private float $tax = 0.0, private string $tax_status = 'none', private array $taxes = [] ) {}
    public function meta_exists( $key ) { return array_key_exists( $key, $this->meta ); }
    public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
    public function get_total() { return $this->total; }
    public function get_total_tax() { return $this->tax; }
    public function get_tax_class() { return ''; }
    public function get_name() { return 'Deposit'; }
    public function get_tax_status() { return $this->tax_status; }
    public function get_taxes() { return $this->taxes; }
}
class PATSACBR_Test_Order_Tax {
    public function __construct( private int $rate_id, private float $rate_percent ) {}
    public function get_rate_id() { return $this->rate_id; }
    public function get_rate_percent() { return $this->rate_percent; }
    public function get_shipping_tax_total() { return 0; }
}
class WC_Order extends PATSACBR_Test_Fee {
    public function __construct( array $meta, private array $fees = [], private array $tax_items = [], private bool $prices_include_tax = false ) { parent::__construct( $meta ); }
    public function get_items( $type = 'line_item' ) {
        if ( 'fee' === $type ) {
            return $this->fees;
        }
        if ( 'tax' === $type ) {
            return $this->tax_items;
        }
        return [];
    }
    public function get_shipping_total() { return 0; }
    public function get_shipping_tax() { return 0; }
    public function get_payment_method() { return 'bacs'; }
    public function get_prices_include_tax() { return $this->prices_include_tax; }
}
require_once dirname( __DIR__ ) . '/includes/class-lexware-api-client.php';
$client = ( new ReflectionClass( PATSACBR_API_Client::class ) )->newInstanceWithoutConstructor();
$format = new ReflectionMethod( $client, 'format_line_items' );
$terms = new ReflectionMethod( $client, 'get_payment_terms_for_order' );
$tax_rate = new ReflectionMethod( $client, 'get_tax_rate_for_class' );
$invoice_tax_type = new ReflectionMethod( $client, 'invoice_tax_type' );
$temp_document = new ReflectionMethod( $client, 'write_temporary_invoice_document' );
$checks = 0;
foreach ( [ [ '_rmwc_deposit' => 'yes' ], [ '_clr_deposit' => 'yes' ], [ '_rmwc_fee_type' => 'deposit' ], [ '_clr_fee_type' => 'deposit' ] ] as $meta ) {
    $lines = $format->invoke( $client, new WC_Order( [], [ new PATSACBR_Test_Fee( $meta ) ] ) );
    if ( 'text' !== $lines[0]['type'] || isset( $lines[0]['unitPrice'] ) ) {
        throw new RuntimeException( 'Refundable deposit incorrectly exported as revenue.' );
    }
    ++$checks;
}
$lines = $format->invoke( $client, new WC_Order( [], [ new PATSACBR_Test_Fee( [] ) ] ) );
if ( 'custom' !== $lines[0]['type'] || 150.0 !== (float) $lines[0]['unitPrice']['netAmount'] ) {
    throw new RuntimeException( 'Ordinary fee export was changed.' );
}
++$checks;
foreach ( [ '_rmwc_invoice_payment_terms_addition', '_clr_invoice_payment_terms_addition' ] as $key ) {
    $result = $terms->invoke( $client, new WC_Order( [ $key => 'Deposit terms' ] ) );
    if ( ! str_contains( json_encode( $result ), 'Deposit terms' ) ) {
        throw new RuntimeException( 'Stored rental terms were lost.' );
    }
    ++$checks;
}
$result = $terms->invoke( $client, new WC_Order( [ '_rmwc_invoice_payment_terms_addition' => 'Current', '_clr_invoice_payment_terms_addition' => 'Historic' ] ) );
if ( ! str_contains( json_encode( $result ), 'Current' ) || str_contains( json_encode( $result ), 'Historic' ) ) {
    throw new RuntimeException( 'Canonical terms must take precedence.' );
}
++$checks;
$lines = $format->invoke( $client, new WC_Order( [], [ new PATSACBR_Test_Fee( [ '_rmwc_deposit' => 'no', '_clr_deposit' => 'yes' ] ) ] ) );
if ( 'custom' !== $lines[0]['type'] ) {
    throw new RuntimeException( 'Canonical fee metadata must take precedence.' );
}
++$checks;

// Historical order tax metadata must win when the current WooCommerce tax rule was deleted.
WC_Tax::$rates = [ 42 => 0.0 ];
$historic_item = new PATSACBR_Test_Fee( [], 100.0, 19.0, 'taxable', [ 'total' => [ 42 => 19.0 ], 'subtotal' => [ 42 => 19.0 ] ] );
$historic_order = new WC_Order( [], [], [ new PATSACBR_Test_Order_Tax( 42, 19.0 ) ] );
if ( 19.0 !== (float) $tax_rate->invoke( $client, '', $historic_order, $historic_item ) ) {
    throw new RuntimeException( 'Historical order tax rate was not preserved after deleting the live WooCommerce rate.' );
}
++$checks;

// If neither the order tax item nor the live rate can resolve the percentage, derive it from stored amounts.
$fallback_item = new PATSACBR_Test_Fee( [], -100.0, -19.0, 'taxable', [ 'total' => [ 77 => -19.0 ] ] );
$fallback_order = new WC_Order( [] );
if ( 19.0 !== (float) $tax_rate->invoke( $client, '', $fallback_order, $fallback_item ) ) {
    throw new RuntimeException( 'Tax-rate fallback must support negative taxable fees.' );
}
++$checks;

// Live tax-rate lookup still works when no historical order tax item is available.
WC_Tax::$rates = [ 7 => 7.0 ];
$live_item = new PATSACBR_Test_Fee( [], 100.0, 7.0, 'taxable', [ 'total' => [ 7 => 7.0 ] ] );
if ( 7.0 !== (float) $tax_rate->invoke( $client, '', new WC_Order( [] ), $live_item ) ) {
    throw new RuntimeException( 'Current WooCommerce tax rates must remain usable.' );
}
++$checks;

if ( 'gross' !== $invoice_tax_type->invoke( $client, new WC_Order( [], [], [], true ) ) ) {
    throw new RuntimeException( 'Orders created with tax-inclusive prices must export as gross invoices.' );
}
++$checks;
if ( 'net' !== $invoice_tax_type->invoke( $client, new WC_Order( [], [], [], false ) ) ) {
    throw new RuntimeException( 'Orders created with tax-exclusive prices must export as net invoices.' );
}
++$checks;

$pdf_bytes = "%PDF-1.4\nPATSACBR test\n%%EOF\n";
$temp_path = $temp_document->invoke( $client, $pdf_bytes, 'pdf' );
if ( ! is_string( $temp_path ) || ! str_ends_with( $temp_path, '.pdf' ) || ! is_file( $temp_path ) ) {
    throw new RuntimeException( 'Temporary invoice file was not created with the required extension.' );
}
if ( file_get_contents( $temp_path ) !== $pdf_bytes ) {
    @unlink( $temp_path );
    throw new RuntimeException( 'Temporary invoice file contents differ from the source document.' );
}
if ( DIRECTORY_SEPARATOR === '/' && 0600 !== ( fileperms( $temp_path ) & 0777 ) ) {
    @unlink( $temp_path );
    throw new RuntimeException( 'Temporary invoice file permissions are not restricted to 0600.' );
}
@unlink( $temp_path );
++$checks;

echo "PASS: {$checks} rental metadata and API edge-case checks.\n";
