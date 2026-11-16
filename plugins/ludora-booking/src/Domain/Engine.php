<?php
/**
 * Pure booking policy.
 *
 * @package Ludora\Booking
 */

namespace Ludora\Booking\Domain;

/** Pure booking policy and integer money calculation. Inventory is claimed by Store. */
final class Engine {
	/**
	 * Test half-open UTC intervals for intersection.
	 *
	 * @param int $start First start.
	 * @param int $end First end.
	 * @param int $other_start Second start.
	 * @param int $other_end Second end.
	 * @return bool
	 */
	public static function overlaps( int $start, int $end, int $other_start, int $other_end ): bool {
		return $start < $other_end && $end > $other_start;
	}
	/**
	 * Validate an integer without truncating decimals or accepting booleans.
	 *
	 * @param mixed $value Input value.
	 * @param int   $min Minimum inclusive.
	 * @param int   $max Maximum inclusive.
	 * @return int
	 * @throws \DomainException When the value is invalid.
	 */
	private static function integer( $value, int $min = 0, int $max = 100000000 ): int {
		if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^\d+$/D', $value ) ) ) {
			throw new \DomainException( 'An integer value is required.' );
		}
		if ( $value < $min || $value > $max ) {
			throw new \DomainException( 'An integer value is outside the allowed range.' );
		}
		return (int) $value;
	}
	/**
	 * Resolve an opening boundary, allowing midnight at the end of the day.
	 *
	 * @param string $date Local date.
	 * @param string $time Local clock time.
	 * @param string $zone IANA timezone.
	 * @return int
	 */
	private static function boundary( string $date, string $time, string $zone ): int {
		if ( '24:00' === $time ) {
			$date = ( new \DateTimeImmutable( $date, new \DateTimeZone( 'UTC' ) ) )->modify( '+1 day' )->format( 'Y-m-d' );
			$time = '00:00';
		}
		return LocalTime::parse( $date, $time, $zone );
	}
	/**
	 * Price a line in minor units, rounding half up before tax.
	 *
	 * @param string $name Display name.
	 * @param string $basis Price basis.
	 * @param int    $quantity Billable units; minutes for hourly rates.
	 * @param int    $rate Minor unit rate.
	 * @param int    $divisor Units per rate.
	 * @param int    $tax_rate Net tax in basis points.
	 * @return array
	 */
	private static function line( string $name, string $basis, int $quantity, int $rate, int $divisor, int $tax_rate ): array {
		$net = intdiv( $rate * $quantity + intdiv( $divisor, 2 ), $divisor );
		$tax = intdiv( $net * $tax_rate + 5000, 10000 );
		return array(
			'name'     => $name,
			'basis'    => $basis,
			'quantity' => $quantity,
			'rate'     => $rate,
			'net'      => $net,
			'tax'      => $tax,
			'total'    => $net + $tax,
		);
	}
	/**
	 * Validate policy and produce an authoritative price and resource demand.
	 *
	 * @param array    $room Room configuration with business settings.
	 * @param array    $request Requested booking details.
	 * @param array    $extras Available extra configurations.
	 * @param int|null $now Current UTC epoch; injectable for deterministic tests.
	 * @return array
	 * @throws \DomainException When booking policy rejects the request.
	 */
	public static function quote( array $room, array $request, array $extras = array(), ?int $now = null ): array {
		$now = $now ?? time();
		$id  = self::integer( $room['id'] ?? 0, 1 );
		if ( empty( $room['active'] ) || self::integer( $request['room_id'] ?? 0, 1 ) !== $id ) {
			throw new \DomainException( 'Room is not available.' );
		}
		$zone = $room['timezone'] ?? 'UTC';
		$date = $request['date'] ?? '';
		LocalTime::parse( $date, '12:00', $zone );
		$weekday = (int) ( new \DateTimeImmutable( $date, new \DateTimeZone( 'UTC' ) ) )->format( 'N' );
		if ( in_array( $date, $room['closed_dates'] ?? array(), true ) ) {
			throw new \DomainException( 'The venue is closed on this date.' );
		}
		$opening = array_key_exists( $date, $room['opening_exceptions'] ?? array() ) ? $room['opening_exceptions'][ $date ] : ( $room['weekly'][ $weekday ] ?? array() );
		$windows = array();
		foreach ( $opening as $window ) {
			$from = self::boundary( $date, $window[0], $zone );
			$to   = self::boundary( $date, $window[1], $zone );
			if ( $to <= $from ) {
				throw new \DomainException( 'Invalid opening interval.' ); }
			$windows[] = array( $from, $to );
		}
		usort( $windows, static fn( $a, $b ) => $a[0] <=> $b[0] );
		$mode = $request['mode'] ?? '';
		if ( 'hourly' === $mode ) {
			$start     = LocalTime::parse( $date, $request['start'] ?? '', $zone );
			$duration  = self::integer( $request['duration'] ?? 0, 1, 1440 );
			$increment = self::integer( $room['increment'] ?? 30, 1, 1440 );
			$minute    = (int) substr( $request['start'], 0, 2 ) * 60 + (int) substr( $request['start'], 3, 2 );
			if ( $duration < self::integer( $room['min_duration'] ?? 60 ) || $duration > self::integer( $room['max_duration'] ?? 480 ) || 0 !== $duration % $increment || 0 !== $minute % $increment ) {
				throw new \DomainException( 'Choose an allowed duration and time increment.' );
			}
			$end = $start + $duration * 60;
		} elseif ( 'shift' === $mode ) {
			$shift = $room['shifts'][ $request['shift'] ?? '' ] ?? null;
			if ( ! $shift ) {
				throw new \DomainException( 'Choose a valid shift.' ); }
			$start = self::boundary( $date, $shift['start'], $zone );
			$end   = self::boundary( $date, $shift['end'], $zone );
		} elseif ( 'day' === $mode && $windows ) {
			$start = $windows[0][0];
			$end   = $windows[ count( $windows ) - 1 ][1];
		} else {
			throw new \DomainException( 'Choose a valid rental mode and opening date.' );
		}
		$inside = false;
		foreach ( $windows as $window ) {
			if ( $start >= $window[0] && $end <= $window[1] ) {
				$inside = true; }
		}
		// A day reserves the entire commercial span, including breaks between openings.
		if ( ( ! $inside && 'day' !== $mode ) || $end <= $start ) {
			throw new \DomainException( 'The selected interval is outside opening hours.' ); }
		$notice    = self::integer( $room['notice_minutes'] ?? 0, 0, 525600 );
		$horizon   = self::integer( $room['horizon_days'] ?? 365, 0, 36500 );
		$last_date = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( new \DateTimeZone( $zone ) )->modify( '+' . $horizon . ' days' )->format( 'Y-m-d' );
		if ( $start < $now + $notice * 60 || $date > $last_date ) {
			throw new \DomainException( 'The selected date is outside the booking window.' ); }
		$children = self::integer( $request['children'] ?? 0, 0, 100000 );
		$adults   = self::integer( $request['adults'] ?? 0, 0, 100000 );
		if ( $children + $adults < 1 || $children > self::integer( $room['capacity_children'] ?? 0 ) || $adults > self::integer( $room['capacity_adults'] ?? 0 ) ) {
			throw new \DomainException( 'Attendance exceeds room capacity or is empty.' ); }
		$occupancy_start = $start - self::integer( $room['buffer_before'] ?? 0, 0, 1440 ) * 60;
		$occupancy_end   = $end + self::integer( $room['buffer_after'] ?? 0, 0, 1440 ) * 60;
		foreach ( $room['blocks'] ?? array() as $block ) {
			$block_date = $block['date'] ?? $date;
			$from       = is_int( $block['start'] ?? null ) ? $block['start'] : self::boundary( $block_date, $block['start'], $zone );
			$to         = is_int( $block['end'] ?? null ) ? $block['end'] : self::boundary( $block_date, $block['end'], $zone );
			if ( $to <= $from ) {
				throw new \DomainException( 'Invalid blocked interval.' ); }
			if ( self::overlaps( $occupancy_start, $occupancy_end, $from, $to ) ) {
				throw new \DomainException( 'The selected interval is blocked.' ); }
		}
		$tax_rate  = self::integer( $room['tax_basis_points'] ?? 0, 0, 100000 );
		$rate      = $room['date_rates'][ $date ][ $mode ] ?? $room['weekday_rates'][ $weekday ][ $mode ] ?? $room['rates'][ $mode ] ?? null;
		$rate      = self::integer( $rate );
		$minutes   = intdiv( $end - $start, 60 );
		$lines     = array( self::line( $room['name'] ?? 'Room rental', $mode, 'hourly' === $mode ? $minutes : 1, $rate, 'hourly' === $mode ? 60 : 1, $tax_rate ) );
		$resources = array(
			'room:' . $id => array(
				'quantity' => 1,
				'capacity' => 1,
			),
		);
		$selected  = $request['extras'] ?? array();
		if ( ! is_array( $selected ) ) {
			throw new \DomainException( 'Extras must be quantities indexed by ID.' ); }
		$known = array();
		foreach ( $extras as $extra ) {
			$extra_id           = self::integer( $extra['id'] ?? 0, 1 );
			$known[ $extra_id ] = true;
			$quantity           = self::integer( $selected[ $extra_id ] ?? 0, 0, 100000 );
			$allowed            = ( empty( $extra['rooms'] ) || in_array( $id, array_map( 'intval', $extra['rooms'] ), true ) ) && in_array( $mode, $extra['modes'] ?? array( 'hourly', 'shift', 'day' ), true );
			if ( ! $allowed ) {
				if ( $quantity ) {
					throw new \DomainException( 'This extra is unavailable for this room or mode.' ); }
				continue;
			}
			if ( ! $quantity && ! empty( $extra['required'] ) ) {
				$quantity = max( 1, self::integer( $extra['min'] ?? 0 ) );
			}
			if ( ! $quantity && empty( $extra['required'] ) ) {
				continue; }
			if ( $quantity < max( ! empty( $extra['required'] ) ? 1 : 0, self::integer( $extra['min'] ?? 0 ) ) || $quantity > self::integer( $extra['max'] ?? 1 ) ) {
				throw new \DomainException( 'Choose an allowed extra quantity.' ); }
			$basis = $extra['basis'] ?? 'fixed';
			if ( ! in_array( $basis, array( 'fixed', 'hour', 'attendee', 'quantity' ), true ) || ( 'quantity' !== $basis && 1 !== $quantity ) ) {
				throw new \DomainException( 'This extra requires a single selection.' ); }
			$units = $quantity;
			if ( 'hour' === $basis ) {
				$units = $minutes; }
			if ( 'attendee' === $basis ) {
				$attendee_basis = $extra['attendee_basis'] ?? 'children';
				if ( ! in_array( $attendee_basis, array( 'children', 'adults', 'all' ), true ) ) {
					throw new \DomainException( 'Invalid attendee pricing basis.' ); }
				$units = 'children' === $attendee_basis ? $children : ( 'adults' === $attendee_basis ? $adults : $children + $adults );
			}
			$lines[]  = self::line( $extra['name'] ?? 'Extra', $basis, $units, self::integer( $extra['price'] ?? 0 ), 'hour' === $basis ? 60 : 1, $tax_rate );
			$capacity = self::integer( $extra['capacity'] ?? 0 );
			if ( $capacity ) {
				if ( $quantity > $capacity ) {
					throw new \DomainException( 'Extra quantity exceeds inventory capacity.' ); }
				$resources[ 'extra:' . $extra_id ] = array(
					'quantity' => $quantity,
					'capacity' => $capacity,
				);
			}
		}
		foreach ( $selected as $extra_id => $quantity ) {
			self::integer( $extra_id, 1 );
			self::integer( $quantity, 0, 100000 );
			if ( ! isset( $known[ $extra_id ] ) ) {
				throw new \DomainException( 'Unknown extra.' ); }
		}
		$currency = $room['currency'] ?? 'EUR';
		if ( ! is_string( $currency ) || ! preg_match( '/^[A-Z]{3}$/D', $currency ) ) {
			throw new \DomainException( 'Invalid currency.' ); }
		$precision = self::integer( $room['precision'] ?? 2, 0, 4 );
		$subtotal  = array_sum( array_column( $lines, 'net' ) );
		$tax       = array_sum( array_column( $lines, 'tax' ) );
		return array(
			'start'           => $start,
			'end'             => $end,
			'occupancy_start' => $occupancy_start,
			'occupancy_end'   => $occupancy_end,
			'timezone'        => $zone,
			'currency'        => $currency,
			'precision'       => $precision,
			'lines'           => $lines,
			'subtotal'        => $subtotal,
			'tax'             => $tax,
			'total'           => $subtotal + $tax,
			'resources'       => $resources,
		);
	}
}
