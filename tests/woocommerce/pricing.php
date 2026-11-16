<?php
ob_start();
require '/var/www/html/wp-load.php';
use Ludora\Booking\Demo;
use Ludora\Booking\Settings;
use Ludora\Booking\Store;
use Ludora\Booking\WooCommerce;
$keys = array( 'ludora_settings', 'woocommerce_currency', 'woocommerce_price_num_decimals', 'woocommerce_calc_taxes', 'woocommerce_default_country', 'woocommerce_prices_include_tax', 'woocommerce_tax_round_at_subtotal' );
$backup = array(); foreach ( $keys as $key ) { $backup[ $key ] = get_option( $key, null ); }
$posts = array(); $bookings = array(); $rate = 0; $count = 0;
function fiscal_check( bool $ok, string $label ): void { global $count; if ( ! $ok ) { throw new RuntimeException( $label ); } $count++; echo "PASS: $label\n"; }
try {
 update_option( 'ludora_settings', array_merge( Settings::get(), array( 'mode' => 'woocommerce', 'tax_basis_points' => 9999 ) ) );
 update_option( 'woocommerce_calc_taxes', 'yes' ); update_option( 'woocommerce_default_country', 'ES:M' );
 $rate = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'ES', 'tax_rate_state' => '', 'tax_rate' => '21.0000', 'tax_rate_name' => 'Pricing fixture VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 0, 'tax_rate_class' => '' ) ); WC_Cache_Helper::invalidate_cache_group( 'taxes' );
 $room = wp_insert_post( array( 'post_type' => 'ludora_room', 'post_title' => 'Fiscal room fixture', 'post_status' => 'publish' ) ); $posts[] = $room; $c = Demo::configuration( 'ludora_room' ); $c['rates']['hourly'] = 1099; update_post_meta( $room, '_ludora_config', $c );
 $extras = array(); foreach ( array( 'attendee' => 337, 'fixed' => 155 ) as $basis => $price ) { $id = wp_insert_post( array( 'post_type' => 'ludora_extra', 'post_title' => 'Fiscal ' . $basis, 'post_status' => 'publish' ) ); $posts[] = $id; $e = Demo::configuration( 'ludora_extra' ); $e['basis'] = $basis; $e['price'] = $price; update_post_meta( $id, '_ludora_config', $e ); $extras[ $id ] = 1; }
 $store = new Store();
 foreach ( array( 'EUR' => 2, 'JPY' => 0, 'KWD' => 3 ) as $currency => $precision ) {
  update_option( 'woocommerce_currency', $currency ); update_option( 'woocommerce_price_num_decimals', $precision );
  foreach ( array( false, true ) as $inclusive ) {
   update_option( 'woocommerce_prices_include_tax', $inclusive ? 'yes' : 'no' ); update_option( 'woocommerce_tax_round_at_subtotal', $inclusive ? 'yes' : 'no' );
   $r = array( 'room_id' => $room, 'date' => wp_date( 'Y-m-d', time() + ( 70 + count( $bookings ) ) * DAY_IN_SECONDS ), 'mode' => 'hourly', 'start' => '10:00', 'duration' => 90, 'children' => 3, 'adults' => 2, 'extras' => $extras );
   $row = $store->create( $r, array( 'name' => 'Fiscal test', 'email' => 'fiscal@example.test' ), hash( 'sha256', random_bytes( 32 ) ), bin2hex( random_bytes( 32 ) ), true ); $bookings[] = $row['id']; $q = json_decode( $row['snapshot'], true );
   // Independent golden amounts: 1.5*1099 rounds to1649; 3*337=1011; fixed155 =>2815; VAT591 =>3406.
   fiscal_check( 2815 === $q['subtotal'] && 591 === $q['tax'] && 3406 === $q['total'], $currency . ' exact golden room + attendee + fixed components and VAT' );
   fiscal_check( $currency === $q['currency'] && $precision === $q['precision'], 'WooCommerce currency and precision are authoritative for ' . $currency );
   WooCommerce::checkout( $row );
   fiscal_check( (int) round( (float) WC()->cart->get_total( 'edit' ) * ( 10 ** $precision ) ) === 3406, 'native cart matches snapshot with ' . ( $inclusive ? 'inclusive' : 'exclusive' ) . ' catalog tax display' );
   $response = rest_do_request( new WP_REST_Request( 'GET', '/wc/store/v1/cart' ) ); $cart = json_decode( wp_json_encode( $response->get_data() ), true );
   fiscal_check( '3406' === $cart['totals']['total_price'] && $currency === $cart['totals']['currency_code'], 'Store API minor-unit total matches independently expected amount' );
   fiscal_check( count( $cart['items'][0]['item_data'] ) >= 5, 'native checkout exposes reference, room, schedule and selected extras' );
  }
 }
 echo "FISCAL MATRIX PASS: $count assertions across3 currencies and2 tax settings\n";
} finally {
 if ( WC()->cart ) { WC()->cart->empty_cart(); }
 foreach ( $backup as $key => $value ) { null === $value ? delete_option( $key ) : update_option( $key, $value ); }
 global $wpdb; foreach ( $bookings as $id ) { foreach ( array( 'claims', 'events', 'outbox', 'bookings' ) as $table ) { $wpdb->delete( $wpdb->prefix . 'ludora_' . $table, array( 'bookings' === $table ? 'id' : 'booking_id' => $id ) ); } }
 foreach ( $posts as $id ) { wp_delete_post( $id, true ); foreach ( array( 'room:', 'extra:' ) as $kind ) { $wpdb->delete( $wpdb->prefix . 'ludora_resources', array( 'resource_key' => $kind . $id ) ); } }
 if ( $rate ) { WC_Tax::_delete_tax_rate( $rate ); WC_Cache_Helper::invalidate_cache_group( 'taxes' ); }
}
