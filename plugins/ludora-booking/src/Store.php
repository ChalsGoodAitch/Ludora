<?php
/**
 * Transactional booking persistence and inventory.
 *
 * @package LudoraBooking
 */

namespace Ludora\Booking;

use Ludora\Booking\Domain\Engine;

defined( 'ABSPATH' ) || exit;

/** InnoDB transactions serialize catalogue changes and acquire inventory locks in lexical order. */
final class Store {
	/**
	 * Whether this request has an active domain transaction.
	 *
	 * @var bool
	 */
	private static bool $transaction_active = false;
	/**
	 * WordPress connection; fresh reads are essential for InnoDB locks.
	 *
	 * @var \wpdb
	 */
	private $db;
	/**
	 * Trusted WordPress prefix for domain tables.
	 *
	 * @var string
	 */
	private string $prefix;
	/**
	 * Bind the WordPress database connection and trusted table prefix.
	 */
	public function __construct() {
		global $wpdb;
		$this->db     = $wpdb;
		$this->prefix = $wpdb->prefix . 'ludora_'; }
	/**
	 * Run one serialized booking transaction and roll back any failure.
	 *
	 * @param callable $callback Domain operation performed under the catalog lock.
	 * @throws \LogicException When callers attempt a nested domain transaction.
	 * @throws \Throwable Rethrows any failure after rolling back the transaction.
	 */
	public function transaction( callable $callback ) {
		if ( self::$transaction_active ) {
			throw new \LogicException( 'Nested booking transactions are not supported.' ); }
		self::$transaction_active = true;
		try {
			$this->sql( 'START TRANSACTION' );
			$this->sql( "SELECT resource_key FROM {$this->prefix}resources WHERE resource_key='catalog' FOR UPDATE" );
			$result = $callback();
			$this->sql( 'COMMIT' );
			return $result;
		} catch ( \Throwable $e ) {
			$this->db->query( 'ROLLBACK' );
			throw $e;
		} finally {
			self::$transaction_active = false; }
	}
	/**
	 * Execute a domain statement and fail closed on a database error.
	 *
	 * @param string $sql Prepared statement or trusted fixed transaction statement.
	 * @throws \RuntimeException When storage rejects the operation.
	 */
	private function sql( string $sql ): void {
		if ( false === $this->db->query( $sql ) ) {
			throw new \RuntimeException( 'Storage operation failed.' ); } }
	/**
	 * Read the current booking row without a stale object-cache copy.
	 *
	 * @param int $id Booking identifier.
	 */
	public function get( int $id ): ?array {
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->prefix}bookings WHERE id=%d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null; }
	/**
	 * Look up a booking by its private bearer token hash.
	 *
	 * @param string $token Private access token.
	 */
	public function by_token( string $token ): ?array {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
			return null; }
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->prefix}bookings WHERE token_hash=%s", hash( 'sha256', $token ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
	/**
	 * Derive the retry-stable private token for a reservation request.
	 *
	 * @param string $idempotency Server-derived idempotency key.
	 */
	public static function access_token( string $idempotency ): string {
		return hash_hmac( 'sha256', 'booking' . $idempotency, wp_salt( 'secure_auth' ) ); }
	/**
	 * List bookings with validated administrative filters.
	 *
	 * @param array $filters Room, state and optional business-local date filters.
	 * @param int   $limit Bounded result page size.
	 * @param int   $offset Result page offset.
	 * @throws \DomainException When the date filter is invalid.
	 */
	public function listing( array $filters = array(), int $limit = 1000, int $offset = 0 ): array {
		$where = '1=1';
		$args  = array();
		foreach ( array(
			'room_id' => '%d',
			'state'   => '%s',
		) as $key => $format ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$where .= " AND $key=$format";
				$args[] = $filters[ $key ]; }
		}
		if ( ! empty( $filters['date'] ) ) {
			$local = \DateTimeImmutable::createFromFormat( '!Y-m-d', $filters['date'], new \DateTimeZone( Settings::get()['timezone'] ) );
			if ( ! $local || $local->format( 'Y-m-d' ) !== $filters['date'] ) {
				throw new \DomainException( 'Invalid date filter.' ); }
			$where .= ' AND start_utc >= %d AND start_utc < %d';
			$args[] = $local->getTimestamp();
			$args[] = $local->modify( '+1 day' )->getTimestamp(); }
		$args[] = max( 1, min( 1000, $limit ) );
		$args[] = max( 0, $offset );
		$sql    = "SELECT * FROM {$this->prefix}bookings WHERE $where ORDER BY start_utc DESC,id DESC LIMIT %d OFFSET %d";
		return $this->db->get_results( $this->db->prepare( $sql, $args ), ARRAY_A );
	}
	/**
	 * Stream all filtered rows from a consistent snapshot without locking room inventory.
	 *
	 * @param array $filters Administrative export filters.
	 * @return \Generator
	 * @throws \LogicException When another domain transaction is already active.
	 */
	public function export_rows( array $filters ): \Generator {
		if ( self::$transaction_active ) {
			throw new \LogicException( 'Nested booking transactions are not supported.' ); }
		self::$transaction_active = true;
		try {
			$this->sql( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
			$this->sql( 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY' );
			$offset = 0;
			do {
				$rows = $this->listing( $filters, 1000, $offset );
				foreach ( $rows as $row ) {
					yield $row; }
				$page_count = count( $rows );
				$offset    += $page_count;
			} while ( 1000 === $page_count );
		} finally {
			$this->db->query( 'ROLLBACK' );
			self::$transaction_active = false;
		}
	}
	/**
	 * Build the server quote and check current resource capacity.
	 *
	 * @param array $request Domain booking request.
	 * @param int   $exclude Booking excluded during rescheduling.
	 * @throws \DomainException When the requested booking violates domain rules.
	 */
	public function quote( array $request, int $exclude = 0 ): array {
		if ( ! empty( $request['package_id'] ) ) {
			$package = get_post( (int) $request['package_id'] );
			if ( ! $package || 'publish' !== $package->post_status || 'ludora_package' !== $package->post_type ) {
				throw new \DomainException( 'This package is not available.' ); }
			$config = Catalog::config( $package->ID );
			if ( (int) ( $request['room_id'] ?? 0 ) !== (int) $config['room_id'] || ( $request['mode'] ?? '' ) !== $config['mode'] ) {
				throw new \DomainException( 'The selected package requires its configured room and rental mode.' ); }
			if ( 'shift' === $config['mode'] && ( $request['shift'] ?? '' ) !== $config['shift'] ) {
				throw new \DomainException( 'The selected package requires its configured shift.' ); }
			if ( 'hourly' === $config['mode'] && isset( $config['duration'] ) && (int) ( $request['duration'] ?? 0 ) !== (int) $config['duration'] ) {
				throw new \DomainException( 'The selected package requires its configured duration.' ); }
			foreach ( ( $config['extras'] ?? array() ) as $extra_id => $quantity ) {
				$request['extras'][ $extra_id ] = max( (int) ( $request['extras'][ $extra_id ] ?? 0 ), (int) $quantity ); }
		}
		$room  = Catalog::room( (int) ( $request['room_id'] ?? 0 ) );
		$quote = WooCommerce::price_quote( Engine::quote( $room, $request, Catalog::extras(), time() ) );
		$this->capacity( $quote, $exclude );
		return $quote;
	}
	/**
	 * Lock resources in stable order and enforce half-open inventory capacity.
	 *
	 * @param array $quote Domain quote with occupancy and resources.
	 * @param int   $exclude Booking excluded from occupancy counts.
	 * @throws \RuntimeException When storage rejects the operation.
	 * @throws \DomainException When the requested booking violates domain rules.
	 */
	private function capacity( array $quote, int $exclude = 0 ): void {
		$resources = $quote['resources'];
		ksort( $resources, SORT_STRING );
		foreach ( $resources as $key => $resource ) {
			$qty      = is_array( $resource ) ? (int) $resource['quantity'] : (int) $resource;
			$capacity = is_array( $resource ) ? (int) $resource['capacity'] : 1;
			$this->sql( $this->db->prepare( "INSERT INTO {$this->prefix}resources (resource_key,capacity) VALUES (%s,%d) ON DUPLICATE KEY UPDATE capacity=VALUES(capacity)", $key, $capacity ) );
			$this->sql( $this->db->prepare( "SELECT resource_key FROM {$this->prefix}resources WHERE resource_key=%s FOR UPDATE", $key ) );
			$start = (int) $quote['occupancy_start'];
			$end   = (int) $quote['occupancy_end'];
			$rows  = $this->db->get_results( $this->db->prepare( "SELECT c.start_utc,c.end_utc,c.quantity FROM {$this->prefix}claims c INNER JOIN {$this->prefix}bookings b ON b.id=c.booking_id WHERE c.resource_key=%s AND c.booking_id<>%d AND c.start_utc<%d AND c.end_utc>%d AND b.state IN ('hold','pending','confirmed') AND (b.expires_at IS NULL OR b.expires_at>%d)", $key, $exclude, $end, $start, time() ), ARRAY_A );
			if ( $this->db->last_error ) {
				throw new \RuntimeException( 'Unable to read inventory.' ); }
			$events = array( array( $start, $qty ), array( $end, -$qty ) );
			foreach ( $rows as $row ) {
				$events[] = array( max( $start, (int) $row['start_utc'] ), (int) $row['quantity'] );
				$events[] = array( min( $end, (int) $row['end_utc'] ), - (int) $row['quantity'] ); }
			usort( $events, static fn( $a, $b ) => ( $a[0] === $b[0] ) ? ( $a[1] <=> $b[1] ) : ( $a[0] <=> $b[0] ) );
			$used = 0;
			foreach ( $events as $event ) {
				$used += $event[1];
				if ( $used > $capacity ) {
					throw new \DomainException( 'This room or a limited extra is no longer available. Choose another time.' ); }
			}
		}
	}
	/**
	 * Sign the accepted price and schedule so browser data cannot alter it.
	 *
	 * @param array $quote Server-produced quote snapshot.
	 */
	public static function quote_signature( array $quote ): string {
		return hash_hmac( 'sha256', wp_json_encode( array( $quote['start'], $quote['end'], $quote['lines'], $quote['tax'], $quote['total'], $quote['currency'], $quote['precision'] ) ), wp_salt( 'auth' ) ); }
	/**
	 * Create or reuse one reservation atomically from server catalog data.
	 *
	 * @param array       $request Domain booking request.
	 * @param array       $customer Responsible adult contact and accepted terms.
	 * @param string      $idempotency Server-derived retry identity.
	 * @param string      $token Private access token.
	 * @param bool        $hold Whether this request enters payment checkout.
	 * @param string|null $accepted_quote Signed quote accepted by the visitor.
	 * @throws \RuntimeException When storage rejects the operation.
	 * @throws \DomainException When the requested booking violates domain rules.
	 */
	public function create( array $request, array $customer, string $idempotency, string $token, bool $hold = false, ?string $accepted_quote = null ): array {
		return $this->transaction(
			function () use ( $request, $customer, $idempotency, $token, $hold, $accepted_quote ) {
				$fingerprint = hash( 'sha256', wp_json_encode( array( $request, $customer, $hold ) ) );
				$existing    = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->prefix}bookings WHERE idempotency=%s", $idempotency ), ARRAY_A );
				if ( $existing ) {
					if ( ! hash_equals( $existing['fingerprint'], $fingerprint ) ) {
							throw new \DomainException( 'This retry key was already used for different details.' );
					} return $existing; }
				$quote = $this->quote( $request );
				if ( null !== $accepted_quote && ! hash_equals( self::quote_signature( $quote ), $accepted_quote ) ) {
					throw new \DomainException( 'Your price quote changed. Check availability and price again before sending your request.' ); }
				$settings = Settings::get();
				$row      = array(
					'reference'     => 'LD-' . strtoupper( bin2hex( random_bytes( 6 ) ) ),
					'token_hash'    => hash( 'sha256', $token ),
					'idempotency'   => $idempotency,
					'fingerprint'   => $fingerprint,
					'room_id'       => (int) $request['room_id'],
					'start_utc'     => $quote['start'],
					'end_utc'       => $quote['end'],
					'state'         => $hold ? 'hold' : 'pending',
					'payment_state' => 'pending',
					'expires_at'    => time() + ( $hold ? (int) $settings['hold_minutes'] * 60 : (int) $settings['pending_hours'] * 3600 ),
					'customer'      => wp_json_encode( $customer ),
					'request'       => wp_json_encode( $request ),
					'snapshot'      => wp_json_encode( $quote ),
					'created_at'    => time(),
					'updated_at'    => time(),
				);
				if ( false === $this->db->insert( $this->prefix . 'bookings', $row ) ) {
					throw new \RuntimeException( 'Unable to save reservation.' ); }
				$id = (int) $this->db->insert_id;
				$this->claims( $id, $quote );
				$this->event( $id, 'created', $row['state'] );
				$this->notify( $id, 'received' );
				return $this->get( $id );
			}
		);
	}
	/**
	 * Replace one booking inventory set inside the current domain transaction.
	 *
	 * @param int   $id Booking identifier.
	 * @param array $quote Domain occupancy and resource snapshot.
	 * @throws \RuntimeException When storage rejects the operation.
	 */
	private function claims( int $id, array $quote ): void {
		if ( false === $this->db->delete( $this->prefix . 'claims', array( 'booking_id' => $id ) ) ) {
			throw new \RuntimeException( 'Unable to replace inventory.' ); }
		foreach ( $quote['resources'] as $key => $resource ) {
			if ( false === $this->db->insert(
				$this->prefix . 'claims',
				array(
					'booking_id'   => $id,
					'resource_key' => $key,
					'start_utc'    => $quote['occupancy_start'],
					'end_utc'      => $quote['occupancy_end'],
					'quantity'     => is_array( $resource ) ? $resource['quantity'] : $resource,
				)
			) ) {
				throw new \RuntimeException( 'Unable to claim inventory.' ); }
		}
	}
	/**
	 * Append an immutable audit entry inside the current transaction.
	 *
	 * @param int    $id Booking identifier.
	 * @param string $event Audit event key.
	 * @param string $detail Audit context.
	 * @throws \RuntimeException When storage rejects the operation.
	 */
	public function event( int $id, string $event, string $detail = '' ): void {
		if ( false === $this->db->insert(
			$this->prefix . 'events',
			array(
				'booking_id' => $id,
				'actor'      => get_current_user_id(),
				'event'      => $event,
				'detail'     => $detail,
				'created_at' => time(),
			)
		) ) {
			throw new \RuntimeException( 'Unable to write audit record.' ); }
	}
	/**
	 * Enqueue one idempotent notification inside the current transaction.
	 *
	 * @param int    $id Booking identifier.
	 * @param string $kind Notification event kind.
	 */
	private function notify( int $id, string $kind ): void {
		$this->sql( $this->db->prepare( "INSERT IGNORE INTO {$this->prefix}outbox (booking_id,event_key) VALUES (%d,%s)", $id, $id . ':' . $kind ) ); }
	/**
	 * Reacquire an expired reservation using current calendar, buffers and shared capacities.
	 *
	 * @param array $row Expired reservation and its immutable financial snapshot.
	 * @return string Snapshot with refreshed inventory metadata and original financial amounts.
	 * @throws \DomainException When the original interval is no longer offered or available.
	 */
	private function reacquire( array $row ): string {
		$current = $this->quote( json_decode( $row['request'], true ), (int) $row['id'] );
		if ( (int) $current['start'] !== (int) $row['start_utc'] || (int) $current['end'] !== (int) $row['end_utc'] ) {
			throw new \DomainException( 'This room or a limited extra is no longer available. Choose another time.' );
		}
		$snapshot = json_decode( $row['snapshot'], true );
		foreach ( array( 'occupancy_start', 'occupancy_end', 'resources' ) as $key ) {
			$snapshot[ $key ] = $current[ $key ];
		}
		$this->claims( (int) $row['id'], $snapshot );
		return wp_json_encode( $snapshot );
	}
	/**
	 * Apply an allowed administrative state transition under inventory locks.
	 *
	 * @param int    $id Booking identifier.
	 * @param string $next Requested booking state.
	 * @param string $note Administrative audit note.
	 * @throws \RuntimeException When storage rejects the operation.
	 * @throws \DomainException When the requested booking violates domain rules.
	 */
	public function transition( int $id, string $next, string $note = '' ): array {
		return $this->transaction(
			function () use ( $id, $next, $note ) {
				$row = $this->get( $id );
				if ( ! $row ) {
								throw new \DomainException( 'Reservation not found.' ); }
				if ( $row['state'] === $next ) {
					return $row; }
				\Ludora\Booking\Domain\State::transition( $row['state'], $next );
				if ( 'completed' === $next && (int) $row['end_utc'] > time() ) {
					throw new \DomainException( 'A reservation can only be completed after its end time.' ); }
				if ( 'confirmed' === $next ) {
					if ( $row['expires_at'] && (int) $row['expires_at'] <= time() ) {
						$row['snapshot'] = $this->reacquire( $row );
					} else {
						$this->capacity( json_decode( $row['snapshot'], true ), $id );
					}
				}
				if ( false === $this->db->update(
					$this->prefix . 'bookings',
					array(
						'state'      => $next,
						'expires_at' => 'confirmed' === $next ? null : $row['expires_at'],
						'updated_at' => time(),
						'snapshot'   => $row['snapshot'],
					),
					array( 'id' => $id )
				) ) {
						throw new \RuntimeException( 'Unable to update reservation.' ); }
				$this->event( $id, $next, $note );
				$this->notify( $id, $next );
				return $this->get( $id );
			}
		);
	}
	/**
	 * Move an active reservation while preserving paid fiscal treatment.
	 *
	 * @param int   $id Booking identifier.
	 * @param array $request Replacement domain booking request.
	 * @throws \RuntimeException When storage rejects the operation.
	 * @throws \DomainException When the requested booking violates domain rules.
	 */
	public function reschedule( int $id, array $request ): array {
		return $this->transaction(
			function () use ( $id, $request ) {
				$row = $this->get( $id );
				if ( ! $row || ! in_array( $row['state'], array( 'pending', 'confirmed', 'hold' ), true ) ) {
					throw new \DomainException( 'This reservation cannot be rescheduled.' ); }
				$quote = $this->quote( $request, $id );
				$old   = json_decode( $row['snapshot'], true );
				if ( 'paid' === $row['payment_state'] && ( $quote['total'] !== $old['total'] || $quote['tax'] !== $old['tax'] || $quote['currency'] !== $old['currency'] || $quote['precision'] !== $old['precision'] || ( $quote['wc_tax'] ?? null ) !== ( $old['wc_tax'] ?? null ) ) ) {
					throw new \DomainException( 'A paid reservation must keep the same total and tax treatment. Cancel/refund and create a new reservation when the amount changes.' ); }
				if ( false === $this->db->update(
					$this->prefix . 'bookings',
					array(
						'room_id'    => $request['room_id'],
						'start_utc'  => $quote['start'],
						'end_utc'    => $quote['end'],
						'request'    => wp_json_encode( $request ),
						'snapshot'   => wp_json_encode( $quote ),
						'updated_at' => time(),
					),
					array( 'id' => $id )
				) ) {
						throw new \RuntimeException( 'Unable to reschedule reservation.' ); }
				$this->claims( $id, $quote );
				$this->event( $id, 'rescheduled' );
				return $this->get( $id );
			}
		);
	}
	/**
	 * Read the booking audit history in insertion order.
	 *
	 * @param int $id Booking identifier.
	 */
	public function history( int $id ): array {
		return $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->prefix}events WHERE booking_id=%d ORDER BY id", $id ), ARRAY_A ); }
	/**
	 * Apply a verified payment event atomically and reacquire inventory safely.
	 *
	 * @param int    $id Booking identifier.
	 * @param string $payment Verified payment state.
	 * @param int    $order_id Linked WooCommerce order identifier.
	 * @throws \RuntimeException When storage rejects the operation.
	 * @throws \DomainException When the requested booking violates domain rules.
	 */
	public function payment( int $id, string $payment, int $order_id ): array {
		return $this->transaction(
			function () use ( $id, $payment, $order_id ) {
				$row = $this->get( $id );
				if ( ! $row || ( $row['order_id'] && (int) $row['order_id'] !== $order_id ) ) {
					throw new \DomainException( 'Payment order does not match the reservation.' ); }
				if ( 'refunded' === $row['payment_state'] || $payment === $row['payment_state'] ) {
					return $row; }
				if ( in_array( $row['payment_state'], array( 'paid', 'refunded' ), true ) && in_array( $payment, array( 'pending', 'failed' ), true ) ) {
					return $row; }
				if ( ! in_array( $payment, array( 'pending', 'paid', 'failed', 'refunded' ), true ) ) {
					throw new \DomainException( 'Invalid payment state.' ); }
				$update = array(
					'payment_state' => $payment,
					'order_id'      => $order_id,
					'updated_at'    => time(),
				);
				if ( 'paid' === $payment && ! in_array( $row['state'], array( 'cancelled', 'rejected', 'completed' ), true ) ) {
					try {
						if ( $row['expires_at'] && (int) $row['expires_at'] <= time() ) {
							$update['snapshot'] = $this->reacquire( $row );
						} else {
							$this->capacity( json_decode( $row['snapshot'], true ), $id );
						}
						$update['state']      = 'confirmed';
						$update['expires_at'] = null;
						$this->notify( $id, 'confirmed' ); } catch ( \DomainException $e ) {
						$this->event( $id, 'paid_inventory_conflict', 'Payment received after inventory became unavailable. Venue must arrange refund or alternative.' );
						$this->notify( $id, 'payment_conflict' ); }
				} elseif ( 'paid' === $payment ) {
					$this->event( $id, 'paid_after_cancellation', 'Payment received for a cancelled or closed reservation. Venue review required.' );
					$this->notify( $id, 'payment_conflict' ); }
				if ( 'failed' === $payment && 'hold' === $row['state'] ) {
					$update['state'] = 'cancelled';
					$this->notify( $id, 'cancelled' ); }
				if ( false === $this->db->update( $this->prefix . 'bookings', $update, array( 'id' => $id ) ) ) {
					throw new \RuntimeException( 'Unable to save payment state.' ); }
				$this->event( $id, 'payment_' . $payment );
				return $this->get( $id );
			}
		);
	}
	/**
	 * Expose the private-access booking response without internal tokens.
	 *
	 * @param array $row Stored booking row.
	 */
	public function public_view( array $row ): array {
		$state = $row['state'];
		if ( in_array( $state, array( 'hold', 'pending' ), true ) && (int) $row['expires_at'] <= time() ) {
			$state = 'expired'; }
		return array(
			'reference'     => $row['reference'],
			'state'         => $state,
			'payment_state' => $row['payment_state'],
			'start'         => (int) $row['start_utc'],
			'end'           => (int) $row['end_utc'],
			'expires_at'    => (int) $row['expires_at'],
			'snapshot'      => json_decode( $row['snapshot'], true ),
		);
	}
}
