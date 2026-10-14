<?php
/** Real database regressions. ONLY in an explicitly disposable wp-env database. */
if (!defined('WP_CLI') || !WP_CLI || '1' !== getenv('PATSACBR_RUN_UPGRADE_TESTS')) {
    throw new RuntimeException('Requires disposable database and PATSACBR_RUN_UPGRADE_TESTS=1.');
}
global $wpdb;
$wpdb->suppress_errors(true);
$checks = 0;
$check = static function($condition, $label) use (&$checks) {
    if (!$condition) { throw new RuntimeException($label); }
    ++$checks;
    WP_CLI::log('PASS: ' . $label);
};
$fault = static function($pattern, $callback) {
    $hit = false;
    $filter = static function($sql) use ($pattern, &$hit) {
        if (!$hit && preg_match($pattern, $sql)) { $hit = true; return 'PATSACBR_INTENTIONAL_DATABASE_ERROR'; }
        return $sql;
    };
    add_filter('query', $filter);
    try { $result = $callback(); } finally { remove_filter('query', $filter); }
    if (!$hit) { throw new RuntimeException('Fault not triggered: ' . $pattern); }
    return $result;
};
$reset = static function() { delete_option('patsacbr_prefix_migration_version'); };
$legacy = 'wlc_payment_terms_fixture';
$current = 'patsacbr_payment_terms_fixture';
add_option($legacy, ['value' => 'preserved']);
$reset();
$check(false === $fault('/INSERT INTO .*options.*patsacbr_payment_terms_fixture/s', ['PATSACBR_Legacy_Migration', 'run']), 'Failed option copy stops migration');
$check(['value' => 'preserved'] === get_option($legacy), 'Failed option copy preserves source');
$check(false === get_option('patsacbr_prefix_migration_version'), 'Failed copy does not mark completion');
$check(true === PATSACBR_Legacy_Migration::run(), 'Failed option migration resumes');
$check(['value' => 'preserved'] === get_option($current) && false === get_option($legacy), 'Verified copy removes source only afterwards');
add_option($legacy, 'different');
$reset();
$check(false === PATSACBR_Legacy_Migration::run(), 'Different canonical and legacy options block migration');
$check('different' === get_option($legacy) && ['value' => 'preserved'] === get_option($current), 'Conflict preserves both options');
delete_option($legacy);
add_option($legacy, ['value' => 'preserved']);
$check(false === $fault('/DELETE FROM .*options.*wlc_payment_terms_fixture/s', ['PATSACBR_Legacy_Migration', 'run']), 'Failed source cleanup remains retryable');
$check(['value' => 'preserved'] === get_option($legacy), 'Cleanup failure preserves source');
$check(true === PATSACBR_Legacy_Migration::run(), 'Identical duplicate cleanup resumes');
$reset();
$check(false === $fault('/INSERT INTO .*options.*patsacbr_prefix_migration_version/s', ['PATSACBR_Legacy_Migration', 'run']), 'Completion marker failure is detected');
$check(true === PATSACBR_Legacy_Migration::run(), 'Completion marker retries');
delete_option($current);
update_option('patsacbr_prefix_migration_version', '1');
add_option($legacy, 'from-v1');
$check(true === PATSACBR_Legacy_Migration::run() && 'from-v1' === get_option($current), 'Old completion marker does not skip repaired migration');
delete_option($current);

// Old schema intentionally lacks dedupe_key/locked_at/next_attempt_at. Rows must
// be renamed intact and then upgraded, rather than copied by column position.
$old_table = $wpdb->prefix . 'wlc_queue';
$new_table = $wpdb->prefix . 'patsacbr_queue';
$wpdb->query($wpdb->prepare('TRUNCATE TABLE %i', $new_table));
$wpdb->query($wpdb->prepare("CREATE TABLE %i (id bigint UNSIGNED NOT NULL AUTO_INCREMENT, order_id bigint UNSIGNED NOT NULL, action varchar(50) NOT NULL, status varchar(20) NOT NULL DEFAULT 'pending', attempts int UNSIGNED NOT NULL DEFAULT 0, error_message text DEFAULT NULL, created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id))", $old_table));
$wpdb->insert($old_table, ['id' => 123, 'order_id' => 999, 'action' => 'create_invoice']);
$upgrade = new ReflectionMethod(PATSACBR_Connector::class, 'create_database_tables');
$connector = PATSACBR_Connector::get_instance();
$check(false === $fault('/SELECT COUNT\(\*\).*patsacbr_queue/', static fn() => $upgrade->invoke($connector)), 'Failed destination count stops table migration');
$check('1' === $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $old_table)), 'Count failure preserves old rows');
$check(false === $fault('/DROP TABLE .*patsacbr_queue/', static fn() => $upgrade->invoke($connector)), 'Failed empty destination drop stops upgrade');
$check(false === $fault('/RENAME TABLE/', static fn() => $upgrade->invoke($connector)), 'Failed rename stops upgrade');
$check('1' === $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $old_table)), 'Rename failure preserves old rows');
delete_option('patsacbr_db_version');
$check(false === $fault('/ALTER TABLE .*ADD.*dedupe_key/i', static fn() => $upgrade->invoke($connector)), 'Failed schema change cannot mark upgrade complete');
$check(false === get_option('patsacbr_db_version'), 'Schema failure leaves version marker absent');
$check('123' === $wpdb->get_var($wpdb->prepare('SELECT id FROM %i LIMIT 1', $new_table)), 'Old-schema migration preserves original row IDs');
$check(true === $upgrade->invoke($connector), 'Interrupted schema upgrade resumes');
$check(PATSACBR_DB_VERSION === get_option('patsacbr_db_version'), 'Complete schema writes current version');
$wpdb->query($wpdb->prepare('CREATE TABLE %i LIKE %i', $old_table, $new_table));
$wpdb->insert($old_table, ['id' => 123, 'order_id' => 888, 'action' => 'void_invoice']);
$check(false === $upgrade->invoke($connector), 'Populated tables with colliding IDs never merge');
$check('888' === $wpdb->get_var($wpdb->prepare('SELECT order_id FROM %i LIMIT 1', $old_table)) && '999' === $wpdb->get_var($wpdb->prepare('SELECT order_id FROM %i LIMIT 1', $new_table)), 'Queue conflict retains both complete tables');
$wpdb->query($wpdb->prepare('DROP TABLE %i', $old_table));
$wpdb->query($wpdb->prepare('TRUNCATE TABLE %i', $new_table));
delete_option('patsacbr_db_version');
$check(false === $fault('/INSERT INTO .*options.*patsacbr_db_version/s', static fn() => $upgrade->invoke($connector)), 'DB version marker failure is detected');
$check(true === $upgrade->invoke($connector), 'DB version marker retries');

// Two real DB connections verify shared locking without touching Lexware.
$other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$order = wc_create_order();
$order->update_meta_data('_patsacbr_lexware_invoice_id', '11111111-1111-4111-8111-111111111111');
$order->save();
$order_id = $order->get_id();
$lock = 'patsacbr_order_' . substr(hash('sha256', DB_NAME . '|' . $wpdb->prefix . '|' . $order_id), 0, 40);
$other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $lock));
$called = false;
$result = PATSACBR_Queue_Handler::with_order_lock($order_id, static function() use (&$called) { $called = true; });
$check(is_wp_error($result) && !$called, 'Other connection prevents all order callbacks');
$id = PATSACBR_Queue_Handler::add_to_queue($order_id, 'create_invoice');
$result = PATSACBR_Queue_Handler::process_order_action($order_id, 'create_invoice');
$check(is_wp_error($result) && 'queue_busy' === $result->get_error_code(), 'Busy order prevents queue processing');
$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id=%d', $new_table, $id));
$check('pending' === $row->status && 0 === (int) $row->attempts, 'Contention preserves pending work and retry budget');
$other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $lock));
$wpdb->update($new_table, ['next_attempt_at' => null], ['id' => $id]);
update_option('patsacbr_auto_send_email', 'no');
$network = static function() { throw new RuntimeException('No external API request allowed in this test.'); };
add_filter('pre_http_request', $network);
$check(true === PATSACBR_Queue_Handler::process_order_action($order_id, 'create_invoice'), 'Released lock allows existing invoice to complete without remote request');
remove_filter('pre_http_request', $network);
$wpdb->update($new_table, ['status' => 'manual_check'], ['id' => $id]);
$void_id = PATSACBR_Queue_Handler::add_to_queue($order_id, 'void_invoice');
$result = PATSACBR_Queue_Handler::process_order_action($order_id, 'void_invoice');
$check(is_wp_error($result) && 'queue_manual_check' === $result->get_error_code(), 'Ambiguous create blocks a different write action');
$check('pending' === $wpdb->get_var($wpdb->prepare('SELECT status FROM %i WHERE id=%d', $new_table, $void_id)), 'Blocked different action remains queued');
try {
    PATSACBR_Queue_Handler::with_order_lock($order_id, static function() { throw new RuntimeException('fixture'); });
} catch (RuntimeException $exception) {
    $check('fixture' === $exception->getMessage(), 'Callback exceptions propagate');
}
$check('1' === (string) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $lock)), 'Exception releases the order lock');
$other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $lock));

// Manual form data must pass nonce/capability checks before it reaches the lock.
// The lock callback must use the checked snapshot, not read $_POST again.
$original_post = $_POST;
$original_user = get_current_user_id();
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
$check(!empty($admins), 'Disposable environment provides an administrator');
wp_set_current_user($admins[0]);
$integration = PATSACBR_WooCommerce_Integration::get_instance();
$manual_order = wc_create_order();
$manual_order_id = $manual_order->get_id();
$manual_lock = 'patsacbr_order_' . substr(hash('sha256', DB_NAME . '|' . $wpdb->prefix . '|' . $manual_order_id), 0, 40);
$manual_id = '22222222-2222-4222-8222-222222222222';
$manual_meta = static function($key) use ($manual_order_id) {
    $fresh = wc_get_order($manual_order_id);
    $fresh->read_meta_data(true);
    return $fresh->get_meta($key);
};
$form = static function() use ($manual_order_id, $manual_id) {
    return [
        'patsacbr_manual_invoice_nonce' => wp_create_nonce('patsacbr_save_manual_invoice_' . $manual_order_id),
        'patsacbr_manual_invoice_id' => $manual_id,
        'patsacbr_manual_invoice_number' => wp_slash(" <b>INV-2026/O'Brien</b> "),
    ];
};
try {
    $_POST = $form();
    unset($_POST['patsacbr_manual_invoice_nonce']);
    $integration->save_manual_invoice_data($manual_order_id);
    $check('' === $manual_meta('_patsacbr_lexware_invoice_id'), 'Missing nonce prevents manual link');
    $_POST = $form();
    $_POST['patsacbr_manual_invoice_nonce'] = 'invalid';
    $integration->save_manual_invoice_data($manual_order_id);
    $check('' === $manual_meta('_patsacbr_lexware_invoice_id'), 'Invalid nonce prevents manual link');
    $_POST = $form();
    $_POST['patsacbr_manual_invoice_nonce'] = wp_create_nonce('patsacbr_save_manual_invoice_' . ($manual_order_id + 1));
    $integration->save_manual_invoice_data($manual_order_id);
    $check('' === $manual_meta('_patsacbr_lexware_invoice_id'), 'Nonce for another order prevents manual link');
    $_POST = $form();
    $_POST['patsacbr_manual_invoice_nonce'] = [$_POST['patsacbr_manual_invoice_nonce']];
    $integration->save_manual_invoice_data($manual_order_id);
    $check('' === $manual_meta('_patsacbr_lexware_invoice_id'), 'Array nonce is rejected without a type error');
    wp_set_current_user(0);
    $_POST = $form();
    $integration->save_manual_invoice_data($manual_order_id);
    $check('' === $manual_meta('_patsacbr_lexware_invoice_id'), 'Valid nonce without order-edit capability prevents manual link');
    $check(0 === count(wc_get_order_notes(['order_id' => $manual_order_id])), 'Rejected authorization has no order-note side effects');
    wp_set_current_user($admins[0]);
    foreach (['patsacbr_manual_invoice_id', 'patsacbr_manual_invoice_number'] as $field) {
        $_POST = $form();
        $_POST[$field] = [$_POST[$field]];
        $integration->save_manual_invoice_data($manual_order_id);
        $check('' === $manual_meta('_patsacbr_lexware_invoice_id'), 'Array field cannot create a manual link: ' . $field);
    }
    $_POST = $form();
    $_POST['patsacbr_manual_invoice_id'] = 'not-a-uuid';
    $integration->save_manual_invoice_data($manual_order_id);
    $check('' === $manual_meta('_patsacbr_lexware_invoice_id'), 'Malformed UUID prevents manual link');
    $_POST = $form();
    unset($_POST['patsacbr_manual_invoice_number']);
    $integration->save_manual_invoice_data($manual_order_id);
    $check('' === $manual_meta('_patsacbr_lexware_invoice_id'), 'Incomplete manual link is not saved');
    $check('1' === (string) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $manual_lock)), 'Second connection acquires manual order lock');
    $_POST = $form();
    $integration->save_manual_invoice_data($manual_order_id);
    $check('' === $manual_meta('_patsacbr_lexware_invoice_id'), 'Busy order prevents manual metadata write');
    $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $manual_lock));
    $_POST = $form();
    $post_changed = false;
    $mutate_post = static function($sql) use ($manual_lock, &$post_changed) {
        if (false !== strpos($sql, 'GET_LOCK') && false !== strpos($sql, $manual_lock)) {
            $post_changed = true;
            $_POST['patsacbr_manual_invoice_id'] = '33333333-3333-4333-8333-333333333333';
            $_POST['patsacbr_manual_invoice_number'] = 'UNVERIFIED';
        }
        return $sql;
    };
    add_filter('query', $mutate_post);
    try {
        $integration->save_manual_invoice_data($manual_order_id);
    } finally {
        remove_filter('query', $mutate_post);
    }
    $check($post_changed && $manual_id === $manual_meta('_patsacbr_lexware_invoice_id'), 'Locked callback uses the verified request snapshot');
    $check("INV-2026/O'Brien" === $manual_meta('_patsacbr_lexware_invoice_number'), 'Manual invoice number is unslashed and sanitized');
    $check('yes' === $manual_meta('_patsacbr_manual_invoice_entry'), 'Valid manual link retains its marker');
    $_POST = $form();
    $_POST['patsacbr_manual_invoice_id'] = '44444444-4444-4444-8444-444444444444';
    $integration->save_manual_invoice_data($manual_order_id);
    $check($manual_id === $manual_meta('_patsacbr_lexware_invoice_id'), 'Existing invoice link cannot be overwritten');
} finally {
    $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $manual_lock));
    $_POST = $original_post;
    wp_set_current_user($original_user);
    $manual_order->delete(true);
}
$other->close();
$order->delete(true);
WP_CLI::success($checks . ' migration and order-lock regression checks passed.');
