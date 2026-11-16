<?php
/**
 * Integrate reservation exports, erasure and retention with WordPress.
 *
 * @package Ludora\Booking
 */

namespace Ludora\Booking;

defined( 'ABSPATH' ) || exit;

/** Integrate reservation exports, erasure and retention with WordPress. */
final class Privacy {
	/**
	 * Register privacy callbacks and policy guidance.
	 */
	public static function boot(): void {
		add_filter(
			'wp_privacy_personal_data_exporters',
			static function ( $items ) {
				$items['ludora'] = array(
					'exporter_friendly_name' => __( 'Ludora reservations', 'ludora-booking' ),
					'callback'               => array( self::class, 'export' ),
				);
				return $items;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			static function ( $items ) {
				$items['ludora'] = array(
					'eraser_friendly_name' => __( 'Ludora reservations', 'ludora-booking' ),
					'callback'             => array( self::class, 'erase' ),
				);
				return $items;
			}
		);
		add_action(
			'admin_init',
			static function () {
				if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
					wp_add_privacy_policy_content( 'Ludora Booking', '<p>' . esc_html__( 'The booking form collects the responsible adult’s name, email, optional phone, attendance counts and accepted terms. A private session cookie lasts one hour. Reservation records are kept for the configured retention period; payment records follow WooCommerce retention settings. No child profiles or card details are collected by this plugin.', 'ludora-booking' ) . '</p>' );
				} }
		);
	}
	/**
	 * Read a fresh page of reservations and match its customer email.
	 *
	 * @param string $email Customer email address.
	 * @param int    $page One-based batch page.
	 * @return array Resolved values.
	 */
	private static function matches( string $email, int $page ): array {
		global $wpdb;
		$offset = max( 0, $page - 1 ) * 100;
		// Privacy batches and retention operate on current plugin records, bypassing stale customer-data caches.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ludora_bookings ORDER BY id LIMIT 100 OFFSET %d", $offset ), ARRAY_A );
		return array(
			'rows' => array_values( array_filter( $rows, static fn( $r ) => strtolower( json_decode( $r['customer'], true )['email'] ?? '' ) === strtolower( $email ) ) ),
			'done' => count( $rows ) < 100,
		);
	}
	/**
	 * Export the matching reservation records for a privacy request.
	 *
	 * @param string $email Customer email address.
	 * @param int    $page One-based batch page.
	 * @return array Resolved values.
	 */
	public static function export( string $email, int $page = 1 ): array {
		$match = self::matches( $email, $page );
		$data  = array();
		foreach ( $match['rows'] as $row ) {
			$customer = json_decode( $row['customer'], true );
			$fields   = array();
			foreach ( array_merge(
				$customer,
				array(
					'reference'     => $row['reference'],
					'state'         => $row['state'],
					'payment_state' => $row['payment_state'],
					'start_utc'     => gmdate( 'c', $row['start_utc'] ),
					'end_utc'       => gmdate( 'c', $row['end_utc'] ),
				)
			) as $name => $value ) {
				$fields[] = array(
					'name'  => $name,
					'value' => (string) $value,
				);
			} $data[] = array(
				'group_id'    => 'ludora',
				'group_label' => __( 'Reservations', 'ludora-booking' ),
				'item_id'     => 'ludora-' . $row['id'],
				'data'        => $fields,
			); }
		return array(
			'data' => $data,
			'done' => $match['done'],
		);
	}
	/**
	 * Anonymize resolved reservations while retaining active requests.
	 *
	 * @param string $email Customer email address.
	 * @param int    $page One-based batch page.
	 * @return array Resolved values.
	 */
	public static function erase( string $email, int $page = 1 ): array {
		$match    = self::matches( $email, $page );
		$removed  = false;
		$retained = false;
		foreach ( $match['rows'] as $row ) {
			$result   = self::anonymize( (int) $row['id'], $email );
			$removed  = $removed || 'removed' === $result;
			$retained = $retained || 'retained' === $result;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'Active reservations are retained until resolved. WooCommerce payment records are handled separately.', 'ludora-booking' ) ) : array(),
			'done'           => $match['done'],
		);
	}
	/**
	 * Recheck identity and state under the same catalog lock used by payment transitions.
	 *
	 * @param int         $id Reservation identifier.
	 * @param string|null $email Requested owner, or null for retention maintenance.
	 * @param int|null    $before Retention cutoff in UTC seconds.
	 * @return string Whether the row was removed, retained or no longer matched.
	 * @throws \RuntimeException When anonymization cannot be persisted.
	 */
	private static function anonymize( int $id, ?string $email, ?int $before = null ): string {
		$store = new Store();
		return $store->transaction(
			static function () use ( $store, $id, $email, $before ) {
				global $wpdb;
				$row = $store->get( $id );
				if ( ! $row ) {
					return 'unmatched';
				}
				$customer = json_decode( $row['customer'], true );
				if ( null !== $email && strtolower( $customer['email'] ?? '' ) !== strtolower( $email ) ) {
					return 'unmatched';
				}
				$active = 'confirmed' === $row['state'] || ( in_array( $row['state'], array( 'hold', 'pending' ), true ) && ( ! $row['expires_at'] || (int) $row['expires_at'] > time() ) );
				if ( $active || ( null !== $before && ( (int) $row['end_utc'] >= $before || in_array( $row['state'], array( 'hold', 'pending', 'confirmed' ), true ) ) ) ) {
					return 'retained';
				}
				// The domain transaction serializes this identity mutation against payment and reservation transitions.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$updated = $wpdb->update(
					$wpdb->prefix . 'ludora_bookings',
					array(
						'customer'   => wp_json_encode(
							array(
								'name'  => 'Anonymized',
								'email' => '',
								'phone' => '',
							)
						),
						'token_hash' => hash( 'sha256', random_bytes( 32 ) ),
					),
					array( 'id' => $id )
				);
				if ( false === $updated ) {
					throw new \RuntimeException( 'Unable to anonymize reservation.' );
				}
				return 'removed';
			}
		);
	}

	/**
	 * Expire pending requests and apply configured data retention.
	 */
	public static function maintenance(): void {
		global $wpdb;
		$store = new Store();
		// Privacy batches and retention operate on current plugin records, bypassing stale customer-data caches.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}ludora_bookings WHERE state IN ('hold','pending') AND expires_at<=%d LIMIT 200", time() ) );
		foreach ( $rows as $id ) {
			try {
				$store->transition( (int) $id, 'expired' ); } catch ( \Throwable $e ) {
				// Next maintenance run retries. Availability ignores expiration independently.
				continue; }
		}
		$before = time() - (int) Settings::get()['retention_days'] * DAY_IN_SECONDS;
		// Select candidates without trusting their state until the domain lock is acquired.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$expired = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}ludora_bookings WHERE end_utc<%d AND state NOT IN ('hold','pending','confirmed')", $before ) );
		foreach ( $expired as $id ) {
			self::anonymize( (int) $id, null, $before );
		}
		// Rate counters contain only salted hashes; rolling expiry is achieved by deleting old buckets daily.
		$last = (int) get_option( 'ludora_rate_cleanup', 0 );
		if ( $last < time() - DAY_IN_SECONDS ) {
			// Privacy batches and retention operate on current plugin records, bypassing stale customer-data caches.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ludora\\_rate\\_%'" );
			update_option( 'ludora_rate_cleanup', time(), false ); }
		Mail::flush();
	}
}
