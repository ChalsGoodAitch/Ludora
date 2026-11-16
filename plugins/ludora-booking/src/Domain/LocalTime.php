<?php
/**
 * Strict local time parsing.
 *
 * @package Ludora\Booking
 */

namespace Ludora\Booking\Domain;

/** Strict wall-clock conversion: ambiguous and nonexistent local times are errors. */
final class LocalTime {
	/**
	 * Convert an unambiguous local minute to a UTC timestamp.
	 *
	 * @param string $date Calendar date in Y-m-d format.
	 * @param string $time Wall-clock time in H:i format.
	 * @param string $timezone IANA business timezone.
	 * @return int
	 * @throws \DomainException When the date, timezone or local time is invalid.
	 */
	public static function parse( string $date, string $time, string $timezone ): int {
		if ( ! in_array( $timezone, \DateTimeZone::listIdentifiers(), true ) && 'UTC' !== $timezone ) {
			throw new \DomainException( 'An IANA business timezone is required.' );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) || ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time ) ) {
			throw new \DomainException( 'Use a valid date and HH:MM local time.' );
		}
		$wall = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, new \DateTimeZone( 'UTC' ) );
		if ( ! $wall || $wall->format( 'Y-m-d H:i' ) !== $date . ' ' . $time ) {
			throw new \DomainException( 'Invalid calendar date.' );
		}
		$zone       = new \DateTimeZone( $timezone );
		$stamp      = $wall->getTimestamp();
		$candidates = array();
		foreach ( $zone->getTransitions( $stamp - 172800, $stamp + 172800 ) as $transition ) {
			$candidate = $stamp - $transition['offset'];
			$local     = ( new \DateTimeImmutable( '@' . $candidate ) )->setTimezone( $zone );
			if ( $local->format( 'Y-m-d H:i' ) === $date . ' ' . $time ) {
				$candidates[ $candidate ] = $candidate;
			}
		}
		if ( 1 !== count( $candidates ) ) {
			throw new \DomainException( 'This local time is ambiguous or does not exist because of a clock change.' );
		}
		return (int) reset( $candidates );
	}
}
