<?php
require '/var/www/html/wp-load.php';
if ( 'delete' === ( $argv[1] ?? '' ) ) {
	$id = (int) $argv[2];
	if ( '1' !== get_post_meta( $id, '_ludora_browser_fixture', true ) ) { throw new RuntimeException( 'Unknown fixture.' ); }
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}ludora_bookings WHERE room_id=%d", $id ) );
	foreach ( $ids as $booking_id ) { foreach ( array( 'claims', 'events', 'outbox', 'bookings' ) as $table ) { $wpdb->delete( $wpdb->prefix . 'ludora_' . $table, array( 'bookings' === $table ? 'id' : 'booking_id' => $booking_id ) ); } }
	$wpdb->delete( $wpdb->prefix . 'ludora_resources', array( 'resource_key' => 'room:' . $id ) ); wp_delete_post( $id, true ); exit;
}
$id = wp_insert_post( array( 'post_type' => 'ludora_room', 'post_title' => 'Keyboard browser fixture', 'post_status' => 'publish', 'meta_input' => array( '_ludora_browser_fixture' => '1', '_ludora_config' => Ludora\Booking\Demo::configuration( 'ludora_room' ) ) ) );
echo $id;
