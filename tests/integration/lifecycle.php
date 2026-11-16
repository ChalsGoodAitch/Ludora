<?php
/** Local lifecycle persistence verification. Requires an exclusive test window. */
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

use Ludora\Booking\Demo;
use Ludora\Booking\Settings;
use Ludora\Booking\Store;

if ( 'probe' === ( $argv[1] ?? '' ) ) {
	global $wpdb;
	$id = (int) $argv[2];
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT id,room_id,start_utc,end_utc,state,payment_state,snapshot FROM {$wpdb->prefix}ludora_bookings WHERE id=%d", $id ), ARRAY_A );
	echo wp_json_encode( array( 'row' => $row, 'theme' => get_stylesheet(), 'plugin_loaded' => class_exists( Store::class ), 'wc_loaded' => function_exists( 'wc_create_order' ), 'schema' => (int) get_option( 'ludora_schema_version' ) ) );
	exit;
}
$count = 0;
function ludora_lifecycle_check( bool $condition, string $label ): void {
	global $count;
	if ( ! $condition ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	++$count;
	echo 'PASS: ' . $label . "\n";
}
function ludora_lifecycle_probe( int $id ): array {
	$pipes = array();
	$process = proc_open( array( PHP_BINARY, __FILE__, 'probe', (string) $id ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Unable to start fresh WordPress probe.' ); }
	fclose( $pipes[0] );
	$output = stream_get_contents( $pipes[1] );
	$error = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	if ( 0 !== proc_close( $process ) ) { throw new RuntimeException( 'Probe failed: ' . $error ); }
	$data = json_decode( $output, true );
	if ( ! is_array( $data ) ) { throw new RuntimeException( 'Unexpected probe output: ' . $output ); }
	return $data;
}
if ( 'manual' !== Settings::get()['mode'] ) { throw new RuntimeException( 'Run lifecycle suite in manual mode.' ); }
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $admins ) { throw new RuntimeException( 'An administrator is required for the demo importer.' ); }
wp_set_current_user( (int) $admins[0] );
global $wpdb;
$prefix = $wpdb->prefix . 'ludora_';
$original_plugins = get_option( 'active_plugins', array() );
$original_theme = get_stylesheet();
$original_schema = get_option( 'ludora_schema_version' );
$original_report = get_option( 'ludora_demo_report', null );
$reading = array();
foreach ( array( 'show_on_front', 'page_on_front', 'page_for_posts' ) as $option ) { $reading[ $option ] = get_option( $option ); }
$demo_before = $wpdb->get_col( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_ludora_demo_key','_ludora_demo_photo')" );
$demo_created = array();
$room_id = 0;
$booking_id = 0;
$customized_id = 0;
$customized_title = '';
$customized_thumbnail = 0;
try {
	Demo::import( 'es', false );
	$first_ids = $wpdb->get_col( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_ludora_demo_key','_ludora_demo_photo') ORDER BY post_id" );
	$demo_created = array_diff( $first_ids, $demo_before );
	$customized_id = (int) $wpdb->get_var( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_ludora_demo_key' AND meta_value='es:room-0' LIMIT 1" );
	if ( ! $customized_id ) { throw new RuntimeException( 'Spanish demo room was not imported.' ); }
	$customized_title = get_the_title( $customized_id );
	$customized_thumbnail = get_post_thumbnail_id( $customized_id );
	delete_post_thumbnail( $customized_id );
	wp_update_post( array( 'ID' => $customized_id, 'post_title' => 'Lifecycle customized room title' ) );
	Demo::import( 'es', false );
	$second_ids = $wpdb->get_col( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_ludora_demo_key','_ludora_demo_photo') ORDER BY post_id" );
	ludora_lifecycle_check( $first_ids === $second_ids, 'repeating Spanish demo import creates no duplicate demo posts or photos' );
	ludora_lifecycle_check( 'Lifecycle customized room title' === get_the_title( $customized_id ), 'reimport preserves an existing customized room title' );
	ludora_lifecycle_check( ! has_post_thumbnail( $customized_id ), 'reimport preserves an explicitly removed room featured image' );
	wp_update_post( array( 'ID' => $customized_id, 'post_title' => $customized_title ) );
	if ( $customized_thumbnail ) { set_post_thumbnail( $customized_id, $customized_thumbnail ); }
	$customized_id = 0;
	$current_reading = array();
	foreach ( array_keys( $reading ) as $option ) { $current_reading[ $option ] = get_option( $option ); }
	ludora_lifecycle_check( $reading === $current_reading, 'demo import without homepage selection preserves Reading settings' );

	$room_id = wp_insert_post( array( 'post_type' => 'ludora_room', 'post_status' => 'publish', 'post_title' => 'Lifecycle persistence fixture' ), true );
	if ( is_wp_error( $room_id ) ) { throw new RuntimeException( $room_id->get_error_message() ); }
	$config = Demo::configuration( 'ludora_room' );
	update_post_meta( $room_id, '_ludora_config', $config );
	$request = array( 'room_id' => $room_id, 'date' => wp_date( 'Y-m-d', time() + 60 * DAY_IN_SECONDS, new DateTimeZone( Settings::get()['timezone'] ) ), 'mode' => 'shift', 'shift' => 'morning', 'children' => 5, 'adults' => 2, 'extras' => array() );
	$store = new Store();
	$row = $store->create( $request, array( 'name' => 'Lifecycle Adult', 'email' => 'lifecycle@example.test' ), hash( 'sha256', random_bytes( 32 ) ), bin2hex( random_bytes( 32 ) ) );
	$booking_id = (int) $row['id'];
	$baseline = ludora_lifecycle_probe( $booking_id );
	$stock_theme = '';
	foreach ( wp_get_themes() as $slug => $theme ) {
		if ( str_starts_with( $slug, 'twentytwenty' ) && ! $theme->errors() ) { $stock_theme = $slug; break; }
	}
	if ( ! $stock_theme ) { throw new RuntimeException( 'Install a stock bundled WordPress theme before this suite.' ); }
	switch_theme( $stock_theme );
	$stock = ludora_lifecycle_probe( $booking_id );
	ludora_lifecycle_check( $stock_theme === $stock['theme'] && $baseline['row'] === $stock['row'] && $stock['plugin_loaded'], 'stock theme retains identical plugin booking data in a fresh request' );
	switch_theme( $original_theme );

	$wc = 'woocommerce/woocommerce.php';
	if ( in_array( $wc, $original_plugins, true ) ) {
		deactivate_plugins( $wc );
		$no_wc = ludora_lifecycle_probe( $booking_id );
		ludora_lifecycle_check( ! $no_wc['wc_loaded'] && $no_wc['plugin_loaded'] && $baseline['row'] === $no_wc['row'], 'without WooCommerce, booking plugin loads and preserves existing data' );
		$result = activate_plugin( $wc );
		if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
	}
	$booking_plugin = 'ludora-booking/ludora-booking.php';
	deactivate_plugins( $booking_plugin );
	$disabled = ludora_lifecycle_probe( $booking_id );
	ludora_lifecycle_check( ! $disabled['plugin_loaded'] && $baseline['row'] === $disabled['row'], 'plugin deactivation leaves existing booking rows and snapshots intact' );
	$result = activate_plugin( $booking_plugin );
	if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
	$reactivated = ludora_lifecycle_probe( $booking_id );
	ludora_lifecycle_check( $reactivated['plugin_loaded'] && $baseline['row'] === $reactivated['row'], 'plugin reactivation preserves existing data and snapshots' );
	update_option( 'ludora_schema_version', 0 );
	$updated = ludora_lifecycle_probe( $booking_id );
	ludora_lifecycle_check( 2 === $updated['schema'] && $updated['plugin_loaded'] && $baseline['row'] === $updated['row'], 'schema upgrade rerun is non-destructive to existing bookings' );
	echo "LIFECYCLE INTEGRATION PASS: $count assertions\n";
} finally {
	if ( get_stylesheet() !== $original_theme ) { switch_theme( $original_theme ); }
	foreach ( $original_plugins as $plugin ) {
		if ( ! is_plugin_active( $plugin ) ) { activate_plugin( $plugin ); }
	}
	update_option( 'active_plugins', $original_plugins );
	update_option( 'ludora_schema_version', $original_schema );
	foreach ( $reading as $option => $value ) { update_option( $option, $value ); }
	if ( null === $original_report ) { delete_option( 'ludora_demo_report' ); } else { update_option( 'ludora_demo_report', $original_report, false ); }
	if ( $customized_id ) { wp_update_post( array( 'ID' => $customized_id, 'post_title' => $customized_title ) ); if ( $customized_thumbnail ) { set_post_thumbnail( $customized_id, $customized_thumbnail ); } }
	if ( $booking_id ) {
		foreach ( array( 'claims', 'events', 'outbox', 'bookings' ) as $table ) { $wpdb->delete( $prefix . $table, array( 'bookings' === $table ? 'id' : 'booking_id' => $booking_id ) ); }
	}
	if ( is_int( $room_id ) && $room_id ) {
		wp_delete_post( $room_id, true );
		$wpdb->delete( $prefix . 'resources', array( 'resource_key' => 'room:' . $room_id ) );
	}
	foreach ( $demo_created as $post_id ) {
		if ( 'attachment' === get_post_type( $post_id ) ) { wp_delete_attachment( (int) $post_id, true ); } else { wp_delete_post( (int) $post_id, true ); }
	}
}
