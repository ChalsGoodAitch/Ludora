<?php
/** Isolated native Checkout Blocks browser fixture; always pair with browser-cleanup.php. */
require '/var/www/html/wp-load.php';

use Ludora\Booking\Demo;
use Ludora\Booking\Settings;
use Ludora\Booking\Store;

$fixture_path = '/tmp/ludora-wc-browser-fixture.json';
if ( '--inspect' === ( $argv[1] ?? '' ) ) {
	$fixture = json_decode( file_get_contents( $fixture_path ), true );
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ludora_bookings WHERE room_id=%d AND order_id=%d", $fixture['room_id'], (int) $argv[2] ), ARRAY_A );
	if ( ! $row ) { throw new RuntimeException( 'Fixture booking/order linkage missing.' ); }
	$order = wc_get_order( (int) $row['order_id'] );
	echo wp_json_encode( array( 'booking_state' => $row['state'], 'payment_state' => $row['payment_state'], 'order_status' => $order->get_status(), 'total' => $order->get_total(), 'currency' => $order->get_currency(), 'item_count' => count( $order->get_items() ), 'payment_method' => $order->get_payment_method(), 'data_store' => $order->get_data_store()->get_current_class_name() ) );
	exit;
}
if ( file_exists( $fixture_path ) ) { throw new RuntimeException( 'Browser fixture already exists; clean up before rerunning.' ); }
if ( ! class_exists( 'WooCommerce' ) || 'yes' !== get_option( 'woocommerce_custom_orders_table_enabled' ) ) { throw new RuntimeException( 'Active WooCommerce with HPOS required.' ); }
$checkout = get_post( (int) get_option( 'woocommerce_checkout_page_id' ) );
if ( ! $checkout || ! has_block( 'woocommerce/checkout', $checkout ) ) { throw new RuntimeException( 'Native Checkout Blocks page required.' ); }
$keys = array( 'woocommerce_coming_soon', 'ludora_settings', 'woocommerce_currency', 'woocommerce_price_num_decimals', 'woocommerce_calc_taxes', 'woocommerce_default_country', 'woocommerce_store_address', 'woocommerce_store_city', 'woocommerce_store_postcode', 'woocommerce_bacs_settings', 'woocommerce_enable_guest_checkout', 'woocommerce_checkout_phone_field', 'woocommerce_checkout_company_field', 'woocommerce_checkout_address_2_field' );
$fixture = array( 'backup' => array(), 'room_id' => 0, 'carrier_id' => (int) get_option( 'ludora_wc_product', 0 ), 'date' => wp_date( 'Y-m-d', time() + 42 * DAY_IN_SECONDS, new DateTimeZone( Settings::get()['timezone'] ) ) );
foreach ( $keys as $key ) { $fixture['backup'][ $key ] = get_option( $key, null ); }
file_put_contents( $fixture_path, wp_json_encode( $fixture ) );
update_option( 'ludora_settings', array_merge( Settings::get(), array( 'mode' => 'woocommerce' ) ) );
foreach ( array( 'woocommerce_coming_soon' => 'no', 'woocommerce_currency' => 'EUR', 'woocommerce_price_num_decimals' => 2, 'woocommerce_calc_taxes' => 'no', 'woocommerce_default_country' => 'ES:M', 'woocommerce_store_address' => 'Local test street 1', 'woocommerce_store_city' => 'Madrid', 'woocommerce_store_postcode' => '28001', 'woocommerce_enable_guest_checkout' => 'yes', 'woocommerce_checkout_phone_field' => 'optional', 'woocommerce_checkout_company_field' => 'hidden', 'woocommerce_checkout_address_2_field' => 'hidden' ) as $key => $value ) { update_option( $key, $value ); }
update_option( 'woocommerce_bacs_settings', array( 'enabled' => 'yes', 'title' => 'Local test bank transfer', 'description' => 'Offline local test. Do not transfer funds.', 'instructions' => 'Local automated test only. No payment was collected.' ) );
$room = wp_insert_post( array( 'post_type' => 'ludora_room', 'post_title' => 'Blocks browser fixture room', 'post_status' => 'publish' ), true );
if ( is_wp_error( $room ) ) { throw new RuntimeException( $room->get_error_message() ); }
$fixture['room_id'] = $room;
file_put_contents( $fixture_path, wp_json_encode( $fixture ) );
$config = Demo::configuration( 'ludora_room' );
$config['notice_minutes'] = 0;
$config['buffer_before'] = 0;
$config['buffer_after'] = 0;
update_post_meta( $room, '_ludora_config', $config );
echo wp_json_encode( array( 'room_id' => $room, 'date' => $fixture['date'], 'checkout_path' => wp_parse_url( get_permalink( $checkout ), PHP_URL_PATH ) ) );
