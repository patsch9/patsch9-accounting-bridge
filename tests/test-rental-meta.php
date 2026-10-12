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
class PATSACBR_Test_Fee {
    public function __construct( private array $meta ) {}
    public function meta_exists( $key ) { return array_key_exists( $key, $this->meta ); }
    public function get_meta( $key, $single = true ) { return $this->meta[ $key ] ?? ''; }
    public function get_total() { return 150; }
    public function get_total_tax() { return 0; }
    public function get_tax_class() { return ''; }
    public function get_name() { return 'Deposit'; }
    public function get_tax_status() { return 'none'; }
}
class WC_Order extends PATSACBR_Test_Fee {
    public function __construct( array $meta, private array $fees = [] ) { parent::__construct( $meta ); }
    public function get_items( $type ) { return 'fee' === $type ? $this->fees : []; }
    public function get_shipping_total() { return 0; }
    public function get_shipping_tax() { return 0; }
    public function get_payment_method() { return 'bacs'; }
}
require_once dirname( __DIR__ ) . '/includes/class-lexware-api-client.php';
$client = ( new ReflectionClass( PATSACBR_API_Client::class ) )->newInstanceWithoutConstructor();
$format = new ReflectionMethod( $client, 'format_line_items' );
$terms = new ReflectionMethod( $client, 'get_payment_terms_for_order' );
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
echo "PASS: {$checks} rental metadata export checks.\n";
