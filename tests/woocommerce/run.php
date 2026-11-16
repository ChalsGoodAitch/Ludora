<?php
/** Real WordPress/WooCommerce/database integration. No gateway charges or remote services. */
ob_start();
require '/var/www/html/wp-load.php';
use Ludora\Booking\{Demo, Store, WooCommerce};
function ludora_wc_check( bool $value, string $label ): void { if ( ! $value ) { throw new RuntimeException( 'FAIL: ' . $label ); } echo "PASS: $label\n"; }
global $wpdb;
$options = array( 'ludora_settings', 'woocommerce_custom_orders_table_enabled', 'woocommerce_currency', 'woocommerce_price_num_decimals', 'woocommerce_calc_taxes', 'woocommerce_default_country', 'woocommerce_store_postcode', 'woocommerce_store_city', 'woocommerce_prices_include_tax', 'woocommerce_bacs_settings' );
$backup = array(); foreach ( $options as $key ) { $backup[$key] = get_option( $key, null ); }
$bookings = array(); $orders = array(); $room = 0; $rate = 0;
try {
 update_option( 'ludora_settings', array_merge( Ludora\Booking\Settings::get(), array( 'mode' => 'woocommerce', 'tax_basis_points' => 9999 ) ) );
 update_option( 'woocommerce_currency', 'EUR' ); update_option( 'woocommerce_price_num_decimals', 2 ); update_option( 'woocommerce_calc_taxes', 'yes' ); update_option( 'woocommerce_default_country', 'ES:M' ); update_option( 'woocommerce_store_postcode', '28001' ); update_option( 'woocommerce_store_city', 'Madrid' ); update_option( 'woocommerce_prices_include_tax', 'no' );
 update_option( 'woocommerce_bacs_settings', array( 'enabled' => 'yes', 'title' => 'Local test bank transfer', 'description' => 'Local automated test only.' ) );
 $rate = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'ES', 'tax_rate_state' => '', 'tax_rate' => '21.0000', 'tax_rate_name' => 'Ludora test VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 0, 'tax_rate_class' => '' ) );
 WC_Cache_Helper::invalidate_cache_group( 'taxes' );
 WC()->countries = new WC_Countries();
 WC()->payment_gateways()->payment_gateways()['bacs']->enabled = 'yes';
 ludora_wc_check( WooCommerce::enabled(), 'optional integration enabled with WooCommerce present' );
 ludora_wc_check( Ludora\Booking\Settings::get()['tax_basis_points'] === 9999, 'manual tax setting exists independently' );
 $room = wp_insert_post( array( 'post_type' => 'ludora_room', 'post_title' => 'WC integration-only room', 'post_status' => 'publish' ) );
 $config = Demo::configuration( 'ludora_room' ); $config['buffer_after'] = 0; $config['notice_minutes'] = 0; update_post_meta( $room, '_ludora_config', $config );
 $request = array( 'room_id' => $room, 'date' => wp_date( 'Y-m-d', time() + 30 * DAY_IN_SECONDS, new DateTimeZone( 'Europe/Madrid' ) ), 'mode' => 'hourly', 'start' => '10:00', 'duration' => 120, 'children' => 10, 'adults' => 2, 'extras' => array() );
 $customer = array( 'name' => 'Test Adult', 'email' => 'wc@example.test', 'phone' => '', 'terms' => true );
 $store = new Store(); $row = $store->create( $request, $customer, bin2hex( random_bytes(32) ), bin2hex( random_bytes(32) ), true ); $bookings[] = $row['id'];
 $q = json_decode( $row['snapshot'], true );
 ludora_wc_check( isset( $q['wc_tax'] ) && $q['tax'] === (int) round( $q['subtotal'] * .21 ), 'WC standard venue VAT replaces manual tax authority' );
 $url = WooCommerce::checkout( $row );
 ludora_wc_check( $url === wc_get_checkout_url() && WC()->cart->get_cart_contents_count() === 1, 'native checkout contains one virtual reservation' );
 ludora_wc_check( (int) round( (float) WC()->cart->get_total( 'edit' ) * 100 ) === $q['total'], 'native cart total equals immutable quote' );
 $cart_request = new WP_REST_Request( 'GET', '/wc/store/v1/cart' ); $cart_response = rest_do_request( $cart_request );
 ludora_wc_check( 200 === $cart_response->get_status(), 'official Store API exposes reservation cart to Checkout Blocks' );
 $checkout = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
 $checkout->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
 $checkout->set_body_params( array( 'billing_address' => array( 'first_name' => 'Test', 'last_name' => 'Adult', 'company' => '', 'address_1' => 'Test street 1', 'address_2' => '', 'city' => 'Madrid', 'state' => 'M', 'postcode' => '28001', 'country' => 'ES', 'email' => 'wc@example.test', 'phone' => '600000000' ), 'payment_method' => 'bacs', 'payment_data' => array(), 'customer_note' => '' ) );
 $response = rest_do_request( $checkout );
 if ( $response->get_status() >= 400 ) { echo wp_json_encode( $response->get_data(), JSON_PRETTY_PRINT ) . "\n"; }
 ludora_wc_check( 200 === $response->get_status(), 'official Checkout Blocks Store API creates an order without a real charge' );
 $data = $response->get_data(); $order = wc_get_order( $data['order_id'] ); $orders[] = $order->get_id();
 ludora_wc_check( (int) $store->get( (int) $row['id'] )['order_id'] === $order->get_id(), 'Blocks order links reservation atomically before payment' );
 ludora_wc_check( count( $order->get_items() ) === 1 && (int) round( (float) $order->get_total() * 100 ) === $q['total'], 'order charges full immutable amount once' );
 $order->payment_complete( 'local-test-only' );
 ludora_wc_check( 'paid' === $store->get( (int) $row['id'] )['payment_state'] && 'confirmed' === $store->get( (int) $row['id'] )['state'], 'gateway payment completion confirms domain reservation' );
 $history = count( $store->history( (int) $row['id'] ) ); WooCommerce::sync( $order->get_id() );
 ludora_wc_check( $history === count( $store->history( (int) $row['id'] ) ), 'duplicate gateway callback is idempotent' );
 $order->update_status( 'failed' ); ludora_wc_check( 'paid' === $store->get( (int) $row['id'] )['payment_state'], 'late failure cannot regress paid domain state' );
 $order->update_status( 'completed' );
 $refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 1, 'reason' => 'Local partial-refund test', 'refund_payment' => false, 'restock_items' => false ) );
 ludora_wc_check( ! is_wp_error( $refund ) && 'paid' === $store->get( (int) $row['id'] )['payment_state'] && 'confirmed' === $store->get( (int) $row['id'] )['state'], 'partial refund does not cancel booking or mark full refund' );
 // A paid reschedule with the same fiscal snapshot must remain refundable.
 $moved = array_merge( $request, array( 'start' => '12:00' ) );
 $store->reschedule( (int) $row['id'], $moved );
 $refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => (float) $order->get_remaining_refund_amount(), 'reason' => 'Local full-refund test', 'refund_payment' => false, 'restock_items' => false ) );
 ludora_wc_check( ! is_wp_error( $refund ) && 'refunded' === $store->get( (int) $row['id'] )['payment_state'], 'full refund after same-price reschedule preserves fiscal linkage' );
 foreach ( array( false, true ) as $inclusive ) {
  update_option( 'woocommerce_prices_include_tax', $inclusive ? 'yes' : 'no' );
  $request['date'] = wp_date( 'Y-m-d', time() + ( $inclusive ? 32 : 31 ) * DAY_IN_SECONDS, new DateTimeZone( 'Europe/Madrid' ) );
  $classic_row = $store->create( $request, $customer, bin2hex( random_bytes(32) ), bin2hex( random_bytes(32) ), true ); $bookings[] = $classic_row['id'];
  WooCommerce::checkout( $classic_row );
  $classic_id = WC()->checkout()->create_order( array( 'billing_first_name' => 'Classic', 'billing_last_name' => 'Test', 'billing_email' => 'wc@example.test', 'billing_country' => 'ES', 'billing_state' => 'M', 'billing_postcode' => '28001', 'billing_city' => 'Madrid', 'payment_method' => 'bacs', 'terms' => 1 ) );
  if ( is_wp_error( $classic_id ) ) { echo $classic_id->get_error_message() . "\n"; }
  ludora_wc_check( ! is_wp_error( $classic_id ) && $classic_id > 0, 'official classic WC_Checkout creates ' . ( $inclusive ? 'tax-inclusive' : 'tax-exclusive' ) . ' order' );
  $orders[] = $classic_id; $classic = wc_get_order( $classic_id );
  ludora_wc_check( (int) $store->get( (int) $classic_row['id'] )['order_id'] === $classic_id, 'classic order links reservation before gateway invocation' );
  $classic->update_status( 'cancelled' );
  ludora_wc_check( 'failed' === $store->get( (int) $classic_row['id'] )['payment_state'] && 'cancelled' === $store->get( (int) $classic_row['id'] )['state'], 'unpaid WooCommerce cancellation releases domain hold' );
 }
 // An expired hold cannot displace the replacement when a delayed gateway confirms payment.
 update_option( 'woocommerce_prices_include_tax', 'no' );
 $request['date'] = wp_date( 'Y-m-d', time() + 33 * DAY_IN_SECONDS, new DateTimeZone( 'Europe/Madrid' ) );
 $late = $store->create( $request, $customer, bin2hex( random_bytes(32) ), bin2hex( random_bytes(32) ), true ); $bookings[] = $late['id'];
 WooCommerce::checkout( $late );
 $late_id = WC()->checkout()->create_order( array( 'billing_first_name' => 'Late', 'billing_email' => 'wc@example.test', 'billing_country' => 'ES', 'billing_state' => 'M', 'payment_method' => 'bacs' ) );
 ludora_wc_check( ! is_wp_error( $late_id ), 'late-payment fixture uses an official native order' );
 $orders[] = $late_id; $late_order = wc_get_order( $late_id );
 $wpdb->update( $wpdb->prefix . 'ludora_bookings', array( 'expires_at' => time() - 1 ), array( 'id' => $late['id'] ) );
 $replacement = $store->create( $request, $customer, bin2hex( random_bytes(32) ), bin2hex( random_bytes(32) ), true ); $bookings[] = $replacement['id'];
 $late_order->payment_complete( 'local-delayed-test' );
 $late_row = $store->get( (int) $late['id'] );
 ludora_wc_check( 'paid' === $late_row['payment_state'] && 'confirmed' !== $late_row['state'], 'late gateway payment records receipt without stealing replacement inventory' );
 ludora_wc_check( in_array( 'paid_inventory_conflict', array_column( $store->history( (int) $late['id'] ), 'event' ), true ), 'late payment inventory conflict is auditable for venue refund or alternative' );
 $request['date'] = wp_date( 'Y-m-d', time() + 34 * DAY_IN_SECONDS, new DateTimeZone( 'Europe/Madrid' ) );
 $cancelled = $store->create( $request, $customer, bin2hex( random_bytes(32) ), bin2hex( random_bytes(32) ), true ); $bookings[] = $cancelled['id'];
 WooCommerce::checkout( $cancelled );
 $cancelled_id = WC()->checkout()->create_order( array( 'billing_first_name' => 'Cancelled', 'billing_email' => 'wc@example.test', 'billing_country' => 'ES', 'billing_state' => 'M', 'payment_method' => 'bacs' ) );
 ludora_wc_check( ! is_wp_error( $cancelled_id ), 'failed-payment fixture uses an official native order' );
 $orders[] = $cancelled_id; $cancelled_order = wc_get_order( $cancelled_id ); $cancelled_order->update_status( 'failed' );
 ludora_wc_check( 'failed' === $store->get( (int) $cancelled['id'] )['payment_state'], 'gateway failure records failed payment' );
 $cancelled_order->payment_complete( 'local-out-of-order-test' );
 ludora_wc_check( 'paid' === $store->get( (int) $cancelled['id'] )['payment_state'] && 'cancelled' === $store->get( (int) $cancelled['id'] )['state'], 'late paid callback after failure does not reopen cancelled booking' );
 ludora_wc_check( in_array( 'paid_after_cancellation', array_column( $store->history( (int) $cancelled['id'] ), 'event' ), true ), 'late cancelled payment raises venue-review audit event' );
 echo 'ORDER DATA STORE: ' . get_class( $order->get_data_store()->get_current_class_name() ? $order->get_data_store() : $order ) . ' ' . $order->get_data_store()->get_current_class_name() . "\n";
 echo "WOOCOMMERCE INTEGRATION PASS\n";
} finally {
 foreach ( $orders as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
 foreach ( $bookings as $id ) { foreach ( array( 'claims', 'events', 'outbox', 'bookings' ) as $table ) { $wpdb->delete( $wpdb->prefix . 'ludora_' . $table, array( 'bookings' === $table ? 'id' : 'booking_id' => $id ) ); } }
 if ( $room ) { wp_delete_post( $room, true ); } if ( $rate ) { WC_Tax::_delete_tax_rate( $rate ); } WC_Cache_Helper::invalidate_cache_group( 'taxes' );
 foreach ( $backup as $key => $value ) { if ( null === $value ) { delete_option( $key ); } else { update_option( $key, $value ); } }
 if ( WC()->cart ) { WC()->cart->empty_cart(); }
}
