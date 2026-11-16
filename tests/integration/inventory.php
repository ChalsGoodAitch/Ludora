<?php
/** Real WordPress/InnoDB policy, shared inventory and immutable price checks. */
require '/var/www/html/wp-load.php';

use Ludora\Booking\Demo;
use Ludora\Booking\Settings;
use Ludora\Booking\Store;

$assertions = 0;
function ludora_inventory_check( bool $condition, string $label ): void {
	global $assertions;
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$assertions;
	echo 'PASS: ' . $label . "\n";
}
function ludora_inventory_rejected( callable $callback, string $label ): void {
	try { $callback(); } catch ( DomainException $error ) {
		ludora_inventory_check( true, $label );
		return;
	}
	throw new RuntimeException( 'FAIL (expected rejection): ' . $label );
}
function ludora_inventory_key(): string { return hash( 'sha256', random_bytes( 32 ) ); }

if ( 'manual' !== Settings::get()['mode'] ) {
	throw new RuntimeException( 'This suite requires manual mode; run after WooCommerce suites restore settings.' );
}
$store = new Store();
global $wpdb;
$prefix = $wpdb->prefix . 'ludora_';
$posts = array();
$bookings = array();
$resources = array();
$zone = new DateTimeZone( Settings::get()['timezone'] );
$base = ( new DateTimeImmutable( 'now', $zone ) )->modify( '+40 days' );
$date = static fn( int $offset ): string => $base->modify( '+' . $offset . ' days' )->format( 'Y-m-d' );
$customer = array( 'name' => 'Inventory Test Adult', 'email' => 'inventory@example.test', 'phone' => '', 'terms' => true );
$config = Demo::configuration( 'ludora_room' );
$config['buffer_after'] = 0;
$config['buffer_before'] = 0;
$config['notice_minutes'] = 0;
$config['capacity_children'] = 20;
$config['capacity_adults'] = 10;
$create = static function ( array $request, bool $hold = false, ?string $signature = null ) use ( $store, $customer, &$bookings ): array {
	$row = $store->create( $request, $customer, ludora_inventory_key(), ludora_inventory_key(), $hold, $signature );
	$bookings[] = (int) $row['id'];
	return $row;
};
try {
	$marker = 'test:nested:' . bin2hex( random_bytes( 8 ) );
	$resources[] = $marker;
	try {
		$store->transaction( static function () use ( $wpdb, $prefix, $marker ) {
			$wpdb->insert( $prefix . 'resources', array( 'resource_key' => $marker, 'capacity' => 1 ) );
			( new Store() )->transaction( static fn() => null );
		} );
		throw new RuntimeException( 'Nested transaction was unexpectedly accepted.' );
	} catch ( LogicException $error ) {
		ludora_inventory_check( null === $wpdb->get_var( $wpdb->prepare( "SELECT resource_key FROM {$prefix}resources WHERE resource_key=%s", $marker ) ), 'nested transaction across Store instances rejects before implicit commit and rolls back outer write' );
	}
	ludora_inventory_check( 17 === $store->transaction( static fn() => 17 ), 'transaction guard resets after failure and permits subsequent work' );
	foreach ( array( 'A', 'B' ) as $suffix ) {
		$id = wp_insert_post( array( 'post_type' => 'ludora_room', 'post_title' => 'Inventory suite room ' . $suffix, 'post_status' => 'publish' ), true );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
		$posts[] = $id;
		$resources[] = 'room:' . $id;
		update_post_meta( $id, '_ludora_config', $config );
	}
	list( $room_a, $room_b ) = $posts;
	$extra_id = wp_insert_post( array( 'post_type' => 'ludora_extra', 'post_title' => 'Single shared inventory test host', 'post_status' => 'publish' ), true );
	if ( is_wp_error( $extra_id ) ) { throw new RuntimeException( $extra_id->get_error_message() ); }
	$posts[] = $extra_id;
	$resources[] = 'extra:' . $extra_id;
	$extra = Demo::configuration( 'ludora_extra' );
	$extra['basis'] = 'hour';
	$extra['capacity'] = 1;
	$extra['price'] = 4000;
	$extra['rooms'] = array( $room_a, $room_b );
	update_post_meta( $extra_id, '_ludora_config', $extra );
	$request = array( 'room_id' => $room_a, 'date' => $date( 0 ), 'mode' => 'hourly', 'start' => '10:00', 'duration' => 120, 'children' => 5, 'adults' => 2, 'extras' => array( $extra_id => 1 ) );
	$first = $create( $request );
	$other_room = array_replace( $request, array( 'room_id' => $room_b ) );
	ludora_inventory_rejected( fn() => $create( $other_room ), 'limited extra shared across distinct rooms rejects simultaneous use' );
	$without_extra = array_replace( $other_room, array( 'extras' => array() ) );
	$second = $create( $without_extra );
	ludora_inventory_check( 'pending' === $second['state'], 'other room remains bookable when shared extra is omitted' );
	$adjacent = array_replace( $other_room, array( 'start' => '12:00' ) );
	$third = $create( $adjacent );
	ludora_inventory_check( 'pending' === $third['state'], 'shared extra and room allow half-open adjacency without margins' );
	$claims = $wpdb->get_results( $wpdb->prepare( "SELECT resource_key,quantity FROM {$prefix}claims WHERE booking_id=%d ORDER BY resource_key", $first['id'] ), ARRAY_A );
	ludora_inventory_check( 2 === count( $claims ) && 'extra:' . $extra_id === $claims[0]['resource_key'] && 1 === (int) $claims[0]['quantity'], 'one unified room and extra claim per booking' );

	$day_request = array_replace( $request, array( 'date' => $date( 1 ), 'mode' => 'day', 'extras' => array() ) );
	$whole_day = $create( $day_request );
	ludora_inventory_check( 10 * HOUR_IN_SECONDS === (int) $whole_day['end_utc'] - (int) $whole_day['start_utc'], 'day mode reserves the configured ten-hour commercial day' );
	ludora_inventory_rejected( fn() => $create( array_replace( $day_request, array( 'mode' => 'shift', 'shift' => 'morning' ) ) ), 'commercial day conflicts with morning shift' );
	ludora_inventory_rejected( fn() => $create( array_replace( $day_request, array( 'mode' => 'hourly', 'start' => '16:00', 'duration' => 60 ) ) ), 'commercial day conflicts with hourly rental' );

	$margin_config = $config;
	$margin_config['buffer_after'] = 30;
	update_post_meta( $room_a, '_ludora_config', $margin_config );
	$margin_request = array_replace( $request, array( 'date' => $date( 2 ), 'extras' => array() ) );
	$create( $margin_request );
	ludora_inventory_rejected( fn() => $create( array_replace( $margin_request, array( 'start' => '12:00' ) ) ), 'after margin prevents apparent adjacency' );
	$after_margin = $create( array_replace( $margin_request, array( 'start' => '12:30' ) ) );
	ludora_inventory_check( 'pending' === $after_margin['state'], 'exact end of occupancy margin permits next booking' );
	update_post_meta( $room_a, '_ludora_config', $config );

	$exception_config = $config;
	$exception_day = (int) ( new DateTimeImmutable( $date( 3 ), $zone ) )->format( 'N' );
	$exception_config['weekly'][ $exception_day ] = array();
	$exception_config['opening_exceptions'][ $date( 3 ) ] = array( array( '10:00', '14:00' ) );
	update_post_meta( $room_a, '_ludora_config', $exception_config );
	$exception_request = array_replace( $request, array( 'date' => $date( 3 ), 'extras' => array() ) );
	ludora_inventory_check( 9000 === $store->quote( $exception_request )['subtotal'], 'opening exception overrides weekly closure through catalogue and service' );
	$exception_config['closed_dates'][] = $date( 3 );
	update_post_meta( $room_a, '_ludora_config', $exception_config );
	ludora_inventory_rejected( fn() => $store->quote( $exception_request ), 'explicit closure wins over opening exception' );
	update_post_meta( $room_a, '_ludora_config', $config );
	ludora_inventory_rejected( fn() => $store->quote( array_replace( $exception_request, array( 'children' => 21 ) ) ), 'children capacity enforced through real catalogue' );
	ludora_inventory_rejected( fn() => $store->quote( array_replace( $exception_request, array( 'adults' => 11 ) ) ), 'adult capacity enforced independently' );
	$notice_config = $config;
	$notice_config['notice_minutes'] = 100000;
	update_post_meta( $room_a, '_ludora_config', $notice_config );
	ludora_inventory_rejected( fn() => $store->quote( $exception_request ), 'minimum notice enforced through real catalogue' );
	update_post_meta( $room_a, '_ludora_config', $config );

	$hold_request = array_replace( $request, array( 'date' => $date( 4 ), 'extras' => array() ) );
	$hold = $create( $hold_request, true );
	$wpdb->update( $prefix . 'bookings', array( 'expires_at' => time() - 1 ), array( 'id' => $hold['id'] ) );
	$replacement = $create( $hold_request );
	ludora_inventory_check( 'hold' === $store->get( (int) $hold['id'] )['state'] && 'pending' === $replacement['state'], 'expired hold frees inventory before cron updates stored state' );
	ludora_inventory_rejected( fn() => $store->transition( (int) $hold['id'], 'confirmed' ), 'late confirmation of expired hold cannot displace replacement' );

	$signed_request = array_replace( $request, array( 'date' => $date( 5 ), 'extras' => array() ) );
	$signed_quote = $store->quote( $signed_request );
	$signature = Store::quote_signature( $signed_quote );
	$changed_price = $config;
	$changed_price['rates']['hourly'] = 6000;
	update_post_meta( $room_a, '_ludora_config', $changed_price );
	ludora_inventory_rejected( fn() => $create( $signed_request, false, $signature ), 'signed accepted quote cannot silently accept a changed room rate' );
	update_post_meta( $room_a, '_ludora_config', $config );
	$signed_booking = $create( $signed_request, false, $signature );
	ludora_inventory_check( 'pending' === $signed_booking['state'], 'unchanged signed quote can be persisted' );

	$paid_request = array_replace( $request, array( 'date' => $date( 6 ), 'extras' => array() ) );
	$paid = $create( $paid_request );
	$paid = $store->payment( (int) $paid['id'], 'paid', 0 );
	ludora_inventory_check( 'paid' === $paid['payment_state'] && 'confirmed' === $paid['state'], 'simulated local payment event confirms its existing inventory' );
	$claims_before = $wpdb->get_results( $wpdb->prepare( "SELECT resource_key,start_utc,end_utc,quantity FROM {$prefix}claims WHERE booking_id=%d ORDER BY resource_key", $paid['id'] ), ARRAY_A );
	update_post_meta( $room_a, '_ludora_config', $changed_price );
	ludora_inventory_rejected( fn() => $store->reschedule( (int) $paid['id'], array_replace( $paid_request, array( 'date' => $date( 7 ) ) ) ), 'paid reschedule rejects changed price' );
	$preserved = $store->get( (int) $paid['id'] );
	$claims_after = $wpdb->get_results( $wpdb->prepare( "SELECT resource_key,start_utc,end_utc,quantity FROM {$prefix}claims WHERE booking_id=%d ORDER BY resource_key", $paid['id'] ), ARRAY_A );
	ludora_inventory_check( $paid['snapshot'] === $preserved['snapshot'] && $paid['start_utc'] === $preserved['start_utc'] && $claims_before === $claims_after, 'failed paid reschedule preserves snapshot, time and claims atomically' );
	update_post_meta( $room_a, '_ludora_config', $config );
	echo "INVENTORY INTEGRATION PASS: $assertions assertions\n";
} finally {
	foreach ( $bookings as $booking_id ) {
		foreach ( array( 'claims', 'events', 'outbox', 'bookings' ) as $table ) {
			$wpdb->delete( $prefix . $table, array( 'bookings' === $table ? 'id' : 'booking_id' => $booking_id ) );
		}
	}
	foreach ( $resources as $resource_key ) { $wpdb->delete( $prefix . 'resources', array( 'resource_key' => $resource_key ) ); }
	foreach ( $posts as $post_id ) { wp_delete_post( $post_id, true ); }
}
