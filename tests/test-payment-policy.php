<?php
/** Exercise selection at enqueue, worker, update and API boundaries without remote writes. */
define('ABSPATH', __DIR__);
$options = array();
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function __($value, $domain = '') { return $value; }
function absint($value) { return abs((int) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function sanitize_text_field($value) { return strip_tags($value); }
function wc_get_order($id) { return $GLOBALS['order']; }
class WP_Error { public function __construct(public $code, public $message) {} }
class WC_Order {
    public function __construct(public $method, public $meta = array()) {}
    public function get_payment_method() { return $this->method; }
    public function get_meta($key) { return $this->meta[$key] ?? ''; }
}
require dirname(__DIR__) . '/includes/class-payment-policy.php';
require dirname(__DIR__) . '/includes/class-queue-handler.php';
require dirname(__DIR__) . '/includes/class-lexware-api-client.php';
$checks = 0;
function verify($pass, $message) {
    ++$GLOBALS['checks'];
    if (!$pass) { throw new RuntimeException($message); }
}
$order = new WC_Order('p9od_pay_on_pickup');
verify(PATSACBR_Payment_Policy::allows($order), 'Existing stores continue to allow all methods.');
$options['patsacbr_payment_scope'] = 'selected';
verify(!PATSACBR_Payment_Policy::allows($order), 'Empty selection denies all.');
$options['patsacbr_payment_methods'] = array('paypal', 'bacs');
verify(!PATSACBR_Payment_Policy::allows($order), 'Excluded offline method denied.');
verify(PATSACBR_Payment_Policy::allows(new WC_Order('paypal')), 'Included method allowed.');
verify(!PATSACBR_Payment_Policy::allows(new WC_Order('')), 'No method never matches selected mode.');
verify(array('paypal', 'p9od_pay_on_pickup') === PATSACBR_Payment_Policy::sanitize_methods(array('', array('bad'), 'paypal', 'paypal', '<bad>', 'p9od_pay_on_pickup')), 'Sanitize arrays and preserve valid gateway IDs.');
verify('selected' === PATSACBR_Payment_Policy::sanitize_scope('invalid'), 'Invalid mode fails closed.');
verify(false === PATSACBR_Queue_Handler::add_to_queue(1, 'create_invoice'), 'Excluded methods never enter invoice queue.');
verify(false === PATSACBR_Queue_Handler::add_to_queue(1, 'update_invoice'), 'Excluded methods never enter replacement queue.');
$queue = (new ReflectionClass(PATSACBR_Queue_Handler::class))->newInstanceWithoutConstructor();
foreach (array('handle_create_invoice', 'handle_update_invoice') as $method) {
    $result = (new ReflectionMethod($queue, $method))->invoke($queue, $order, new stdClass());
    verify($result instanceof WP_Error && 'payment_method_excluded' === $result->code, 'Stale queued job blocked before reconciliation, contact sync or credit.');
}
$client = (new ReflectionClass(PATSACBR_API_Client::class))->newInstanceWithoutConstructor();
verify('payment_method_excluded' === $client->create_invoice($order, null)->code, 'Direct API creation is guarded.');
$order->meta['_patsacbr_lexware_invoice_id'] = 'existing-invoice';
$order->meta['_patsacbr_lexware_update_source_invoice_id'] = 'prior-invoice';
$api = new class { public function create_credit_note($order, $id) { return array('id' => 'credit-' . $id); } };
$credit = (new ReflectionMethod($queue, 'handle_void_invoice'))->invoke($queue, $order, $api);
verify('credit-existing-invoice' === $credit['id'], 'Existing invoices may still be credited for excluded methods.');
$options['patsacbr_payment_scope'] = 'all';
verify(PATSACBR_Payment_Policy::allows(new WC_Order('new-gateway')), 'All mode includes gateways added later.');
echo "PASS: $checks payment policy checks.\n";
