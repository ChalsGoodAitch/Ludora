<?php
/** Real WordPress REST, capability, privacy and CSV security regression checks. */
require '/var/www/html/wp-load.php';

use Ludora\Booking\Admin;
use Ludora\Booking\Demo;
use Ludora\Booking\Privacy;
use Ludora\Booking\Settings;
use Ludora\Booking\Store;

$assertions = array();
function ludora_security_check( bool $condition, string $label ): void {
	global $assertions;
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	$assertions[] = 'PASS: ' . $label;
}
function ludora_security_dispatch( string $action, array $payload = array(), string $csrf = '' ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/ludora/v1/' . $action );
	$request->set_header( 'Content-Type', 'application/json' );
	if ( $csrf ) { $request->set_header( 'X-Ludora-CSRF', $csrf ); }
	$request->set_body( wp_json_encode( $payload ) );
	return rest_get_server()->dispatch( $request );
}
function ludora_security_privacy( string $method, string $email ): array {
	$result = array();
	for ( $page = 1; $page < 100; ++$page ) {
		$result[] = Privacy::$method( $email, $page );
		if ( end( $result )['done'] ) { return $result; }
	}
	throw new RuntimeException( 'Privacy pagination did not terminate.' );
}

if ( 'manual' !== Settings::get()['mode'] ) { throw new RuntimeException( 'Security suite requires manual mode.' ); }
global $wpdb;
$prefix = $wpdb->prefix . 'ludora_';
$store = new Store();
$posts = array();
$booking_ids = array();
$fixture_user = 0;
$session = bin2hex( random_bytes( 32 ) );
$csrf = bin2hex( random_bytes( 32 ) );
$transient = 'ludora_s_' . hash( 'sha256', $session );
$email = 'security-' . bin2hex( random_bytes( 6 ) ) . '@example.test';
$previous_cookie = $_COOKIE['ludora_session'] ?? null;
$previous_origin = $_SERVER['HTTP_ORIGIN'] ?? null;
$previous_ip = $_SERVER['REMOTE_ADDR'] ?? null;
$previous_post = $_POST;
$previous_request = $_REQUEST;
$previous_user = get_current_user_id();
$_SERVER['REMOTE_ADDR'] = 'security-suite-' . bin2hex( random_bytes( 8 ) );
wp_set_current_user( 0 );
try {
	$room = wp_insert_post( array( 'post_type' => 'ludora_room', 'post_title' => '=HYPERLINK("https://example.test")', 'post_status' => 'publish' ), true );
	if ( is_wp_error( $room ) ) { throw new RuntimeException( $room->get_error_message() ); }
	$posts[] = $room;
	$config = Demo::configuration( 'ludora_room' );
	$config['notice_minutes'] = 0;
	$config['buffer_before'] = 0;
	$config['buffer_after'] = 0;
	update_post_meta( $room, '_ludora_config', $config );
	$extra = wp_insert_post( array( 'post_type' => 'ludora_extra', 'post_title' => 'Security fixture extra', 'post_status' => 'publish' ), true );
	if ( is_wp_error( $extra ) ) { throw new RuntimeException( $extra->get_error_message() ); }
	$posts[] = $extra;
	$extra_config = Demo::configuration( 'ludora_extra' );
	$extra_config['max'] = 2;
	$extra_config['rooms'] = array( $room );
	update_post_meta( $extra, '_ludora_config', $extra_config );
	$date = ( new DateTimeImmutable( 'now', new DateTimeZone( Settings::get()['timezone'] ) ) )->modify( '+65 days' )->format( 'Y-m-d' );
	$request = array( 'room_id' => $room, 'date' => $date, 'mode' => 'hourly', 'start' => '10:00', 'duration' => 120, 'children' => 5, 'adults' => 2, 'extras' => array() );
	unset( $_COOKIE['ludora_session'], $_SERVER['HTTP_ORIGIN'] );
	ludora_security_check( 403 === ludora_security_dispatch( 'quote', array( 'request' => $request ) )->get_status(), 'anonymous quote without session is forbidden' );
	set_transient( $transient, $csrf, HOUR_IN_SECONDS );
	$_COOKIE['ludora_session'] = $session;
	ludora_security_check( 403 === ludora_security_dispatch( 'quote', array( 'request' => $request ) )->get_status(), 'session cookie without CSRF is forbidden' );
	ludora_security_check( 403 === ludora_security_dispatch( 'quote', array( 'request' => $request ), str_repeat( '0', 64 ) )->get_status(), 'incorrect CSRF is forbidden' );
	$_SERVER['HTTP_ORIGIN'] = 'https://attacker.example';
	ludora_security_check( 403 === ludora_security_dispatch( 'session' )->get_status(), 'foreign-origin session creation is forbidden' );
	ludora_security_check( 403 === ludora_security_dispatch( 'quote', array( 'request' => $request ), $csrf )->get_status(), 'foreign-origin quote with valid CSRF is forbidden' );
	unset( $_SERVER['HTTP_ORIGIN'] );
	ludora_security_check( 400 === ludora_security_dispatch( 'quote', array( 'request' => 'invalid' ), $csrf )->get_status(), 'REST object schema rejects scalar request' );
	ludora_security_check( 400 === ludora_security_dispatch( 'quote', array(), $csrf )->get_status(), 'REST schema rejects missing quote request' );
	$quote_response = ludora_security_dispatch( 'quote', array( 'request' => $request ), $csrf );
	$quote = $quote_response->get_data();
	ludora_security_check( 200 === $quote_response->get_status() && 9000 === $quote['subtotal'], 'authorized quote uses configured two-hour rate' );
	$forged = array_merge( $request, array( 'price' => 1, 'total' => 1, 'tax' => 0, 'currency' => 'USD' ) );
	$forged_quote = ludora_security_dispatch( 'quote', array( 'request' => $forged ), $csrf )->get_data();
	ludora_security_check( $quote['total'] === $forged_quote['total'] && $quote['currency'] === $forged_quote['currency'], 'client-forged money and currency cannot alter quote' );
	foreach ( array( -1, 3, 1.5 ) as $quantity ) {
		$bad = array_replace( $request, array( 'extras' => array( $extra => $quantity ) ) );
		ludora_security_check( 409 === ludora_security_dispatch( 'quote', array( 'request' => $bad ), $csrf )->get_status(), 'invalid extra quantity rejected: ' . $quantity );
	}
	$customer = array( 'name' => '+SUM(1,2)', 'email' => $email, 'phone' => '+3412345', 'terms' => true );
	$payload = array( 'request' => $request, 'customer' => $customer, 'key' => 'security-' . bin2hex( random_bytes( 12 ) ), 'quote_signature' => str_repeat( '0', 64 ) );
	ludora_security_check( 409 === ludora_security_dispatch( 'reserve', $payload, $csrf )->get_status(), 'forged accepted quote signature cannot create reservation' );
	$payload['quote_signature'] = $quote['signature'];
	$created_response = ludora_security_dispatch( 'reserve', $payload, $csrf );
	$created = $created_response->get_data();
	ludora_security_check( 200 === $created_response->get_status(), 'valid signed reservation succeeds' );
	$row = $store->by_token( $created['token'] );
	$booking_ids[] = (int) $row['id'];
	ludora_security_check( strlen( $created['token'] ) === 64 && $row['token_hash'] !== $created['token'] && hash( 'sha256', $created['token'] ) === $row['token_hash'], 'private token is 256 bits and stored only as SHA-256 digest' );
	foreach ( array( $row['id'], $row['reference'], str_repeat( '0', 64 ), "' OR 1=1 --" ) as $lookup ) {
		ludora_security_check( 409 === ludora_security_dispatch( 'status', array( 'token' => (string) $lookup ), $csrf )->get_status(), 'public status rejects guessed lookup: ' . ( is_numeric( $lookup ) ? 'numeric ID' : ( str_starts_with( (string) $lookup, 'LD-' ) ? 'reference' : 'invalid token' ) ) );
	}
	$status = ludora_security_dispatch( 'status', array( 'token' => $created['token'] ), $csrf );
	ludora_security_check( 200 === $status->get_status() && ! array_intersect( array( 'customer', 'email', 'phone', 'token_hash', 'idempotency' ), array_keys( $status->get_data() ) ), 'private status excludes customer identity and secret database fields' );
	$export_method = new ReflectionMethod( Admin::class, 'export' );
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	if ( ! $admins ) { throw new RuntimeException( 'Administrator required for CSV test.' ); }
	wp_set_current_user( $admins[0]->ID );
	$_POST = array( 'room_id' => $room );
	$_REQUEST['_wpnonce'] = wp_create_nonce( 'ludora_action' );
	ob_start();
	$export_method->invoke( null, $store );
	$csv = ob_get_clean();
	wp_set_current_user( 0 );
	unset( $_REQUEST['_wpnonce'] );
	$csv_lines = explode( "\n", trim( $csv ) );
	$csv_row = str_getcsv( $csv_lines[1], ',', '"', '' );
	ludora_security_check( str_starts_with( $csv_row[1], "'=" ) && str_starts_with( $csv_row[6], "'+" ), 'CSV prefixes formula-like room titles and customer names with apostrophe' );
	$privacy_export = ludora_security_privacy( 'export', strtoupper( $email ) );
	$items = array_merge( ...array_column( $privacy_export, 'data' ) );
	ludora_security_check( 1 === count( $items ) && 'ludora-' . $row['id'] === $items[0]['item_id'], 'privacy export matches case-insensitive owner email with no unrelated records' );
	$retained = ludora_security_privacy( 'erase', $email );
	ludora_security_check( in_array( true, array_column( $retained, 'items_retained' ), true ) && $row['customer'] === $store->get( (int) $row['id'] )['customer'], 'privacy erasure retains active reservation identity' );
	$store->transition( (int) $row['id'], 'confirmed' );
	// Own fixture only: model the passage of time without altering the site clock.
	$wpdb->update( $prefix . 'bookings', array( 'end_utc' => time() - HOUR_IN_SECONDS ), array( 'id' => $row['id'] ) );
	$store->transition( (int) $row['id'], 'completed' );
	$removed = ludora_security_privacy( 'erase', $email );
	$anonymized = json_decode( $store->get( (int) $row['id'] )['customer'], true );
	ludora_security_check( in_array( true, array_column( $removed, 'items_removed' ), true ) && array( 'name' => 'Anonymized', 'email' => '', 'phone' => '' ) === $anonymized, 'privacy erasure anonymizes completed reservation' );
	ludora_security_check( null === $store->by_token( $created['token'] ), 'privacy erasure revokes old private access token' );
	$die_handler = static fn() => static function ( $message, $title, $args ) { throw new RuntimeException( 'ludora_security_die_' . ( $args['response'] ?? 500 ) ); };
	add_filter( 'wp_die_handler', $die_handler );
	try {
		try { Admin::action(); throw new LogicException( 'Anonymous admin action unexpectedly returned.' ); }
		catch ( RuntimeException $error ) { ludora_security_check( 'ludora_security_die_403' === $error->getMessage(), 'anonymous admin export/action fails with HTTP 403 before processing' ); }

		$fixture_user = wp_insert_user( array( 'user_login' => 'security-' . bin2hex( random_bytes( 8 ) ), 'user_pass' => wp_generate_password( 40 ), 'user_email' => 'subscriber-' . $email, 'role' => 'subscriber' ) );
		if ( is_wp_error( $fixture_user ) ) { throw new RuntimeException( $fixture_user->get_error_message() ); }
		wp_set_current_user( $fixture_user );
		$_POST['_wpnonce'] = wp_create_nonce( 'ludora_action' );
		try { Admin::action(); throw new LogicException( 'Subscriber admin action unexpectedly returned.' ); }
		catch ( RuntimeException $error ) { ludora_security_check( 'ludora_security_die_403' === $error->getMessage(), 'authenticated subscriber with valid nonce cannot administer reservations' ); }
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
		if ( ! $admins ) { throw new RuntimeException( 'Administrator required for nonce-negative test.' ); }
		wp_set_current_user( $admins[0]->ID );
		ludora_security_check( current_user_can( 'manage_ludora' ), 'administrator capability exists for independent nonce-negative test' );
		unset( $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
		try { Admin::action(); throw new LogicException( 'Administrator action without nonce unexpectedly returned.' ); }
		catch ( RuntimeException $error ) { ludora_security_check( 'ludora_security_die_403' === $error->getMessage(), 'capable administrator without action nonce is forbidden' ); }
	} finally { remove_filter( 'wp_die_handler', $die_handler ); }
	echo implode( "\n", $assertions ) . "\nSECURITY INTEGRATION PASS: " . count( $assertions ) . " assertions\n";
} finally {
	foreach ( $booking_ids as $id ) { foreach ( array( 'claims', 'events', 'outbox', 'bookings' ) as $table ) { $wpdb->delete( $prefix . $table, array( 'bookings' === $table ? 'id' : 'booking_id' => $id ) ); } }
	foreach ( $posts as $id ) { $wpdb->delete( $prefix . 'resources', array( 'resource_key' => ( $id === $room ? 'room:' : 'extra:' ) . $id ) ); wp_delete_post( $id, true ); }
	if ( $fixture_user ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $fixture_user ); }
	delete_transient( $transient );
	$_POST = $previous_post;
	$_REQUEST = $previous_request;
	foreach ( array( array( '_COOKIE', 'ludora_session', $previous_cookie ), array( '_SERVER', 'HTTP_ORIGIN', $previous_origin ), array( '_SERVER', 'REMOTE_ADDR', $previous_ip ) ) as $saved ) {
		if ( null === $saved[2] ) { unset( $GLOBALS[ $saved[0] ][ $saved[1] ] ); } else { $GLOBALS[ $saved[0] ][ $saved[1] ] = $saved[2]; }
	}
	wp_set_current_user( $previous_user );
}
