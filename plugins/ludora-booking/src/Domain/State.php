<?php
/**
 * Reservation and payment state policy.
 *
 * @package Ludora\Booking
 */

namespace Ludora\Booking\Domain;

/** Reservation and payment lifecycles remain independent. */
final class State {
	/**
	 * Validate a lifecycle transition, including idempotent retries.
	 *
	 * @param string $current Current state.
	 * @param string $next Requested state.
	 * @param bool   $payment Whether to use the separate payment lifecycle.
	 * @return string
	 * @throws \DomainException When the transition is invalid.
	 */
	public static function transition( string $current, string $next, bool $payment = false ): string {
		$edges = $payment ? array(
			'pending'  => array( 'paid', 'failed' ),
			'failed'   => array( 'pending', 'paid' ),
			'paid'     => array( 'refunded' ),
			'refunded' => array(),
		) : array(
			'hold'      => array( 'pending', 'confirmed', 'cancelled', 'rejected', 'expired' ),
			'pending'   => array( 'confirmed', 'cancelled', 'rejected', 'expired' ),
			'confirmed' => array( 'cancelled', 'completed' ),
			'cancelled' => array(),
			'rejected'  => array(),
			'expired'   => array(),
			'completed' => array(),
		);
		if ( ! isset( $edges[ $current ] ) || ! isset( $edges[ $next ] ) || ( $current !== $next && ! in_array( $next, $edges[ $current ], true ) ) ) {
			throw new \DomainException( 'Invalid state transition.' );
		}
		return $next;
	}
	/**
	 * Determine whether a booking continues to consume inventory.
	 *
	 * @param string   $state Reservation state.
	 * @param int|null $expires UTC expiration or null for no expiration.
	 * @param int      $now Current UTC epoch.
	 * @return bool
	 */
	public static function blocks( string $state, ?int $expires, int $now ): bool {
		return in_array( $state, array( 'hold', 'pending', 'confirmed' ), true ) && ( null === $expires || $expires > $now );
	}
}
