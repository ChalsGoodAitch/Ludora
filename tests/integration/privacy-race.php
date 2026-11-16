<?php
/** Real InnoDB privacy serialization and core REST catalog authorization checks. */
require '/var/www/html/wp-load.php';

use Ludora\Booking\Demo;
use Ludora\Booking\Privacy;
use Ludora\Booking\Settings;
use Ludora\Booking\Store;

if ( isset( $argv[1] ) && 'worker' === $argv[1] ) {
	$marker = $argv[2];
	add_filter( 'query', static function ( $query ) use ( $marker ) {
		if ( str_contains( $query, "resource_key='catalog' FOR UPDATE" ) ) { touch( $marker ); }
		return $query;
	} );
	if ( 'maintenance' === $argv[3] ) { Privacy::maintenance(); echo 'maintenance'; }
	else {
		$results = array();
		for ( $page = 1; $page < 100; $page++ ) { $results[] = Privacy::erase( $argv[3], $page ); if ( end( $results )['done'] ) { break; } }
		echo wp_json_encode( $results );
	}
	exit;
}

function ludora_privacy_check( bool $condition, string $label ): void {
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	echo 'PASS: ' . $label . "\n";
}
function ludora_catalog_dispatch( string $method, string $path, array $params = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, $path );
	foreach ( $params as $key => $value ) { $request->set_param( $key, $value ); }
	return rest_get_server()->dispatch( $request );
}

global $wpdb;
$store = new Store();
$prefix = $wpdb->prefix . 'ludora_';
$posts = array();
$bookings = array();
$editor = 0;
$original_user = get_current_user_id();
try {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	if ( ! $admins ) { throw new RuntimeException( 'Administrator fixture required.' ); }
	$editor = wp_insert_user( array( 'user_login' => 'catalog-editor-' . bin2hex( random_bytes( 6 ) ), 'user_pass' => wp_generate_password( 40 ), 'role' => 'editor' ) );
	if ( is_wp_error( $editor ) ) { throw new RuntimeException( $editor->get_error_message() ); }
	foreach ( array( 'ludora_room', 'ludora_extra', 'ludora_package' ) as $type ) {
		$id = wp_insert_post( array( 'post_type' => $type, 'post_title' => 'Catalog permission fixture', 'post_status' => 'publish' ) );
		$posts[] = $id;
		$route = '/wp/v2/' . $type;
		wp_set_current_user( $editor );
		ludora_privacy_check( ! current_user_can( 'manage_ludora' ), $type . ': editor has no booking management capability' );
		ludora_privacy_check( 403 === ludora_catalog_dispatch( 'POST', $route, array( 'title' => 'Unauthorized', 'status' => 'publish' ) )->get_status(), $type . ': editor cannot create or publish through core REST' );
		ludora_privacy_check( 403 === ludora_catalog_dispatch( 'POST', $route . '/' . $id, array( 'title' => 'Unauthorized' ) )->get_status(), $type . ': editor cannot update through core REST' );
		ludora_privacy_check( 403 === ludora_catalog_dispatch( 'DELETE', $route . '/' . $id, array( 'force' => true ) )->get_status(), $type . ': editor cannot delete through core REST' );
		wp_set_current_user( 0 );
		ludora_privacy_check( 200 === ludora_catalog_dispatch( 'GET', $route . '/' . $id )->get_status(), $type . ': published catalog remains publicly readable' );
		wp_set_current_user( $admins[0]->ID );
		ludora_privacy_check( current_user_can( 'manage_ludora' ), $type . ': custom capability remains available outside post meta-capability mapping' );
		ludora_privacy_check( 200 === ludora_catalog_dispatch( 'POST', $route . '/' . $id, array( 'title' => 'Authorized', 'status' => 'publish' ) )->get_status(), $type . ': administrator can update and publish through core REST' );
		ludora_privacy_check( 200 === ludora_catalog_dispatch( 'DELETE', $route . '/' . $id, array( 'force' => true ) )->get_status(), $type . ': administrator can delete through core REST' );
	}
	$room = wp_insert_post( array( 'post_type' => 'ludora_room', 'post_title' => 'Privacy race fixture', 'post_status' => 'publish' ) );
	$posts[] = $room;
	$config = Demo::configuration( 'ludora_room' );
	$config['notice_minutes'] = 0;
	update_post_meta( $room, '_ludora_config', $config );
	foreach ( array( 'erase', 'maintenance' ) as $index => $operation ) {
		$date = ( new DateTimeImmutable( 'now', new DateTimeZone( Settings::get()['timezone'] ) ) )->modify( '+' . ( 95 + $index ) . ' days' )->format( 'Y-m-d' );
		$email = 'privacy-race-' . bin2hex( random_bytes( 6 ) ) . '@example.test';
		$key = hash( 'sha256', random_bytes( 32 ) );
		$token = Store::access_token( $key );
		$row = $store->create( array( 'room_id' => $room, 'date' => $date, 'mode' => 'hourly', 'start' => '10:00', 'duration' => 120, 'children' => 5, 'adults' => 2, 'extras' => array() ), array( 'name' => 'Race owner', 'email' => $email ), $key, $token );
		$id = (int) $row['id'];
		$bookings[] = $id;
		$wpdb->update( $prefix . 'bookings', array( 'state' => 'expired', 'expires_at' => time() - 10, 'end_utc' => time() - 4000 * DAY_IN_SECONDS ), array( 'id' => $id ) );
		$marker = '/tmp/ludora-privacy-race-' . bin2hex( random_bytes( 8 ) );
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->query( "SELECT resource_key FROM {$prefix}resources WHERE resource_key='catalog' FOR UPDATE" );
		$pipes = array();
		$process = proc_open( array( PHP_BINARY, __FILE__, 'worker', $marker, 'maintenance' === $operation ? 'maintenance' : $email ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$deadline = microtime( true ) + 20;
		while ( ! file_exists( $marker ) && microtime( true ) < $deadline ) { usleep( 10000 ); clearstatcache( true, $marker ); }
		if ( ! file_exists( $marker ) ) { $wpdb->query( 'ROLLBACK' ); throw new RuntimeException( 'Privacy worker did not reach the domain lock.' ); }
		usleep( 100000 );
		ludora_privacy_check( proc_get_status( $process )['running'], $operation . ': independent privacy process waits for catalog lock' );
		// Model a payment worker confirming after the privacy candidate read, before releasing its transaction.
		$wpdb->update( $prefix . 'bookings', array( 'state' => 'confirmed', 'payment_state' => 'paid', 'expires_at' => null ), array( 'id' => $id ) );
		$wpdb->query( 'COMMIT' );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$errors = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] );
		if ( 0 !== proc_close( $process ) ) { throw new RuntimeException( 'Privacy worker failed: ' . $errors ); }
		unlink( $marker );
		$fresh = $store->get( $id );
		ludora_privacy_check( $row['customer'] === $fresh['customer'] && $row['token_hash'] === $fresh['token_hash'] && 'confirmed' === $fresh['state'], $operation . ': fresh locked state retains newly confirmed owner and token' );
		if ( 'erase' === $operation ) { ludora_privacy_check( in_array( true, array_column( json_decode( $output, true ), 'items_retained' ), true ), 'erasure reports newly confirmed reservation as retained' ); }
	}
	echo "PRIVACY AND CATALOG INTEGRATION PASS\n";
} finally {
	$wpdb->query( 'ROLLBACK' );
	foreach ( $bookings as $id ) { foreach ( array( 'claims', 'events', 'outbox', 'bookings' ) as $table ) { $wpdb->delete( $prefix . $table, array( 'bookings' === $table ? 'id' : 'booking_id' => $id ) ); } }
	foreach ( $posts as $id ) { $wpdb->delete( $prefix . 'resources', array( 'resource_key' => 'room:' . $id ) ); wp_delete_post( $id, true ); }
	if ( $editor && ! is_wp_error( $editor ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $editor ); }
	wp_set_current_user( $original_user );
}
