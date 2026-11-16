<?php
/**
 * Install the transactional booking schema.
 *
 * @package Ludora\Booking
 */

namespace Ludora\Booking;

defined( 'ABSPATH' ) || exit;

/** Install the transactional booking schema. */
final class Installer {
	/** Retain records while disabling scheduled work and the native checkout carrier. */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'ludora_maintenance' );
		$id = (int) get_option( 'ludora_wc_product', 0 );
		if ( $id && 'yes' === get_post_meta( $id, '_ludora_carrier', true ) ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'draft',
				)
			);
		}
	}
	/**
	 * Create InnoDB tables and initialize capabilities, settings and maintenance.
	 *
	 * @throws \RuntimeException When the additive schema migration is incomplete.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$prefix  = $wpdb->prefix . 'ludora_';
		$charset = $wpdb->get_charset_collate();
		$sql     = array(
			"CREATE TABLE {$prefix}resources (
             resource_key varchar(100) NOT NULL,
             capacity int unsigned NOT NULL DEFAULT 1,
             PRIMARY KEY  (resource_key)) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}bookings (
			 id bigint unsigned NOT NULL AUTO_INCREMENT,
			 reference varchar(32) NOT NULL,
			 token_hash char(64) NOT NULL,
			 idempotency char(64) NOT NULL,
			 fingerprint char(64) NOT NULL,
			 room_id bigint unsigned NOT NULL,
             start_utc bigint NOT NULL,
             end_utc bigint NOT NULL,
			 state varchar(24) NOT NULL,
			 payment_state varchar(24) NOT NULL DEFAULT 'pending',
			 expires_at bigint DEFAULT NULL,
			 customer longtext NOT NULL,
			 request longtext NOT NULL,
			 snapshot longtext NOT NULL,
			 order_id bigint unsigned DEFAULT NULL,
             created_at bigint NOT NULL,
			 updated_at bigint NOT NULL,
             PRIMARY KEY  (id),
             UNIQUE KEY reference (reference),
             UNIQUE KEY idempotency (idempotency),
             UNIQUE KEY token_hash (token_hash),
             KEY room_interval (room_id,start_utc,end_utc),
             KEY start_utc (start_utc),
             KEY state_expiry (state,expires_at),
             KEY order_id (order_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}claims (
             id bigint unsigned NOT NULL AUTO_INCREMENT,
             booking_id bigint unsigned NOT NULL,
             resource_key varchar(100) NOT NULL,
             start_utc bigint NOT NULL,
             end_utc bigint NOT NULL,
             quantity int unsigned NOT NULL,
             PRIMARY KEY  (id),
             KEY resource_interval (resource_key,start_utc,end_utc),
             KEY booking_id (booking_id)) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}events (
             id bigint unsigned NOT NULL AUTO_INCREMENT,
             booking_id bigint unsigned NOT NULL,
             actor bigint unsigned NOT NULL DEFAULT 0,
             event varchar(64) NOT NULL,
             detail text NOT NULL,
             created_at bigint NOT NULL,
             PRIMARY KEY  (id),
             KEY booking_id (booking_id)) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}outbox (
             id bigint unsigned NOT NULL AUTO_INCREMENT,
             booking_id bigint unsigned NOT NULL,
             event_key varchar(100) NOT NULL,
             delivered longtext DEFAULT NULL,
             sent_at bigint DEFAULT NULL,
             attempts int unsigned NOT NULL DEFAULT 0,
             PRIMARY KEY  (id),
             UNIQUE KEY event_key (event_key)) ENGINE=InnoDB $charset;",
		);
		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
		// A failed additive migration must never be marked successful or silently lose recipient acknowledgements.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $prefix . 'outbox' ) );
		if ( ! in_array( 'delivered', $columns, true ) ) {
			throw new \RuntimeException( 'The notification database migration did not complete.' );
		}
		// Verify actual engines rather than relying on a host's silent storage-engine substitution policy.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$engines = $wpdb->get_col( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s,%s,%s,%s)', $prefix . 'resources', $prefix . 'bookings', $prefix . 'claims', $prefix . 'events', $prefix . 'outbox' ) );
		if ( count( $engines ) !== 5 || array_filter( $engines, static fn( $engine ) => 'INNODB' !== strtoupper( $engine ) ) ) {
			throw new \RuntimeException( 'Transactional InnoDB tables are required for reservations.' );
		}
		// Initialize the plugin resource lock row atomically; this write has no cacheable result.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (resource_key,capacity) VALUES ('catalog',1)", $prefix . 'resources' ) );
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( 'manage_ludora' ); }
		add_option( 'ludora_settings', Settings::defaults() );
		update_option( 'ludora_schema_version', 2 );
		if ( ! wp_next_scheduled( 'ludora_maintenance' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'ludora_maintenance' ); }
		if ( did_action( 'init' ) ) {
			self::rewrites();
		} else {
			add_action( 'init', array( self::class, 'rewrites' ), 99 );
		}
	}
	/** Register real editorial routes before refreshing rules on installation or migration. */
	public static function rewrites(): void {
		Catalog::register();
		flush_rewrite_rules( false );
	}
}
