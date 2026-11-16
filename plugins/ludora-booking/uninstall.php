<?php
/**
 * Remove retained data only after the owner explicitly enables uninstall purging.
 *
 * @package Ludora\Booking
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
$ludora_settings = get_option( 'ludora_settings', array() );
if ( empty( $ludora_settings['purge'] ) ) {
	return; }
global $wpdb;
foreach ( array( 'claims', 'events', 'outbox', 'bookings', 'resources' ) as $ludora_table ) {
	// Explicit uninstall purging requires dropping the plugin-owned schema.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'ludora_' . $ludora_table ) ); }
$ludora_posts = get_posts(
	array(
		'post_type'   => array( 'ludora_room', 'ludora_extra', 'ludora_package' ),
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)
);
foreach ( $ludora_posts as $ludora_id ) {
	wp_delete_post( $ludora_id, true ); }
$ludora_carrier = (int) get_option( 'ludora_wc_product', 0 );
if ( $ludora_carrier && 'yes' === get_post_meta( $ludora_carrier, '_ludora_carrier', true ) ) {
	wp_delete_post( $ludora_carrier, true );
}
foreach ( array( 'ludora_wc_product', 'ludora_demo_report', 'ludora_rate_cleanup' ) as $ludora_option ) {
	delete_option( $ludora_option );
}
delete_option( 'ludora_settings' );
delete_option( 'ludora_schema_version' );
wp_clear_scheduled_hook( 'ludora_maintenance' );
$ludora_role = get_role( 'administrator' );
if ( $ludora_role ) {
	$ludora_role->remove_cap( 'manage_ludora' ); }
