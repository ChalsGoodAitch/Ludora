<?php
/** Restore browser fixture settings and remove only its booking/order/catalog records. */
require '/var/www/html/wp-load.php';
$fixture_path = '/tmp/ludora-wc-browser-fixture.json';
if ( ! file_exists( $fixture_path ) ) { echo "No Checkout Blocks fixture to restore.\n"; exit; }
$fixture = json_decode( file_get_contents( $fixture_path ), true );
global $wpdb;
$prefix = $wpdb->prefix . 'ludora_';
$rows = $fixture['room_id'] ? $wpdb->get_results( $wpdb->prepare( "SELECT id,order_id FROM {$prefix}bookings WHERE room_id=%d", $fixture['room_id'] ), ARRAY_A ) : array();
foreach ( $rows as $row ) {
	if ( $row['order_id'] && $order = wc_get_order( (int) $row['order_id'] ) ) { $order->delete( true ); }
	foreach ( array( 'claims', 'events', 'outbox', 'bookings' ) as $table ) { $wpdb->delete( $prefix . $table, array( 'bookings' === $table ? 'id' : 'booking_id' => $row['id'] ) ); }
}
// Draft orders may exist before checkout linked them to the fixture booking.
foreach ( wc_get_orders( array( 'limit' => -1, 'billing_email' => 'blocks-browser@example.test', 'status' => array_keys( wc_get_order_statuses() ) ) ) as $order ) { $order->delete( true ); }
if ( $fixture['room_id'] ) { wp_delete_post( $fixture['room_id'], true ); $wpdb->delete( $prefix . 'resources', array( 'resource_key' => 'room:' . $fixture['room_id'] ) ); }
if ( ! $fixture['carrier_id'] && $carrier = (int) get_option( 'ludora_wc_product', 0 ) ) { $product = wc_get_product( $carrier ); if ( $product ) { $product->delete( true ); } delete_option( 'ludora_wc_product' ); }
foreach ( $fixture['backup'] as $key => $value ) { if ( null === $value ) { delete_option( $key ); } else { update_option( $key, $value ); } }
unlink( $fixture_path );
echo 'Checkout Blocks fixture restored; removed ' . count( $rows ) . " isolated bookings and their orders.\n";
