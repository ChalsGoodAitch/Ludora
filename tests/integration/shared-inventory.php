<?php
/** Two independent PHP/DB sessions contend for one extra across two rooms. */
require '/var/www/html/wp-load.php';

use Ludora\Booking\Demo;
use Ludora\Booking\Settings;
use Ludora\Booking\Store;

if ( 'worker' === ( $argv[1] ?? '' ) ) {
	$room_id = (int) $argv[2];
	$extra_id = (int) $argv[3];
	$nonce = preg_replace( '/[^a-f0-9]/', '', $argv[4] );
	$barrier = '/tmp/ludora-shared-' . $nonce;
	touch( $barrier . '-ready-' . $room_id );
	$deadline = microtime( true ) + 20;
	while ( ! file_exists( $barrier ) ) {
		if ( microtime( true ) > $deadline ) { fwrite( STDERR, 'Barrier timeout' ); exit( 2 ); }
		usleep( 10000 );
	}
	$request = array( 'room_id' => $room_id, 'date' => wp_date( 'Y-m-d', time() + 55 * DAY_IN_SECONDS, new DateTimeZone( Settings::get()['timezone'] ) ), 'mode' => 'hourly', 'start' => '10:00', 'duration' => 120, 'children' => 5, 'adults' => 2, 'extras' => array( $extra_id => 1 ) );
	try {
		( new Store() )->create( $request, array( 'name' => 'Concurrent Shared Extra Test', 'email' => 'shared@example.test' ), hash( 'sha256', random_bytes( 32 ) ), bin2hex( random_bytes( 32 ) ) );
		echo 'OCCUPIED';
	} catch ( DomainException $error ) { echo 'CONFLICT'; }
	exit;
}
if ( 'manual' !== Settings::get()['mode'] ) { throw new RuntimeException( 'Run shared inventory suite in manual mode.' ); }
global $wpdb;
$prefix = $wpdb->prefix . 'ludora_';
$posts = array();
$rooms = array();
$processes = array();
$nonce = bin2hex( random_bytes( 8 ) );
$barrier = '/tmp/ludora-shared-' . $nonce;
try {
	foreach ( array( 'A', 'B' ) as $name ) {
		$id = wp_insert_post( array( 'post_type' => 'ludora_room', 'post_status' => 'publish', 'post_title' => 'Shared concurrency room ' . $name ), true );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
		$rooms[] = $id;
		$posts[] = $id;
		$config = Demo::configuration( 'ludora_room' );
		$config['buffer_after'] = 0;
		update_post_meta( $id, '_ludora_config', $config );
	}
	$extra_id = wp_insert_post( array( 'post_type' => 'ludora_extra', 'post_status' => 'publish', 'post_title' => 'Shared concurrency single host' ), true );
	if ( is_wp_error( $extra_id ) ) { throw new RuntimeException( $extra_id->get_error_message() ); }
	$posts[] = $extra_id;
	$extra = Demo::configuration( 'ludora_extra' );
	$extra['capacity'] = 1;
	$extra['rooms'] = $rooms;
	update_post_meta( $extra_id, '_ludora_config', $extra );
	foreach ( $rooms as $room_id ) {
		$pipes = array();
		$process = proc_open( array( PHP_BINARY, __FILE__, 'worker', (string) $room_id, (string) $extra_id, $nonce ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Unable to start worker.' ); }
		$processes[] = array( $process, $pipes );
	}
	$deadline = microtime( true ) + 20;
	while ( ! file_exists( $barrier . '-ready-' . $rooms[0] ) || ! file_exists( $barrier . '-ready-' . $rooms[1] ) ) {
		if ( microtime( true ) > $deadline ) { throw new RuntimeException( 'Workers did not become ready.' ); }
		usleep( 10000 );
	}
	touch( $barrier );
	$outputs = array();
	foreach ( $processes as [ $process, $pipes ] ) {
		fclose( $pipes[0] );
		$outputs[] = stream_get_contents( $pipes[1] );
		$errors = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		if ( 0 !== proc_close( $process ) ) { throw new RuntimeException( 'Worker failed: ' . $errors ); }
	}
	$processes = array();
	sort( $outputs );
	if ( array( 'CONFLICT', 'OCCUPIED' ) !== $outputs ) { throw new RuntimeException( 'Expected one winner: ' . wp_json_encode( $outputs ) ); }
	$claims = $wpdb->get_results( $wpdb->prepare( "SELECT booking_id,quantity FROM {$prefix}claims WHERE resource_key=%s", 'extra:' . $extra_id ), ARRAY_A );
	if ( 1 !== count( $claims ) || 1 !== (int) $claims[0]['quantity'] ) { throw new RuntimeException( 'Expected exactly one shared inventory claim.' ); }
	echo "PASS: Two ready-synchronized PHP processes in distinct rooms: exactly one shared-extra booking, one conflict; one capacity claim persists.\n";
} finally {
	foreach ( $processes as [ $process, $pipes ] ) {
		if ( is_resource( $process ) ) { proc_terminate( $process ); }
		foreach ( $pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
		if ( is_resource( $process ) ) { proc_close( $process ); }
	}
	foreach ( $rooms as $room_id ) {
		$bookings = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$prefix}bookings WHERE room_id=%d", $room_id ) );
		foreach ( $bookings as $booking_id ) {
			foreach ( array( 'claims', 'events', 'outbox', 'bookings' ) as $table ) { $wpdb->delete( $prefix . $table, array( 'bookings' === $table ? 'id' : 'booking_id' => $booking_id ) ); }
		}
		$wpdb->delete( $prefix . 'resources', array( 'resource_key' => 'room:' . $room_id ) );
		if ( file_exists( $barrier . '-ready-' . $room_id ) ) { unlink( $barrier . '-ready-' . $room_id ); }
	}
	if ( isset( $extra_id ) && is_int( $extra_id ) ) { $wpdb->delete( $prefix . 'resources', array( 'resource_key' => 'extra:' . $extra_id ) ); }
	foreach ( $posts as $post_id ) { wp_delete_post( $post_id, true ); }
	if ( file_exists( $barrier ) ) { unlink( $barrier ); }
}
