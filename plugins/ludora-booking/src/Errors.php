<?php
/**
 * Translate domain validation messages at the presentation boundary.
 *
 * @package LudoraBooking
 */

namespace Ludora\Booking;

defined( 'ABSPATH' ) || exit;

/** Only known domain messages may be exposed to visitors. */
final class Errors {
	/**
	 * Localize a known domain error without exposing unexpected internals.
	 *
	 * @param string $message Original domain validation message.
	 * @return string
	 */
	public static function message( string $message ): string {
		$messages = array(
			'A numeric setting is outside its allowed range.' => __( 'A numeric setting is outside its allowed range.', 'ludora-booking' ),
			'A paid reservation must keep the same total and tax treatment. Cancel/refund and create a new reservation when the amount changes.' => __( 'A paid reservation must keep the same total and tax treatment. Cancel/refund and create a new reservation when the amount changes.', 'ludora-booking' ),
			'A reservation can only be completed after its end time.' => __( 'A reservation can only be completed after its end time.', 'ludora-booking' ),
			'A timing rule exceeds the supported range.'   => __( 'A timing rule exceeds the supported range.', 'ludora-booking' ),
			'An IANA business timezone is required.'       => __( 'An IANA business timezone is required.', 'ludora-booking' ),
			'An integer value is outside the allowed range.' => __( 'An integer value is outside the allowed range.', 'ludora-booking' ),
			'An integer value is required.'                => __( 'An integer value is required.', 'ludora-booking' ),
			'Attendance exceeds room capacity or is empty.' => __( 'Attendance exceeds room capacity or is empty.', 'ludora-booking' ),
			'Checkout must contain exactly one reservation.' => __( 'Checkout must contain exactly one reservation.', 'ludora-booking' ),
			'Choose a valid configuration option.'         => __( 'Choose a valid configuration option.', 'ludora-booking' ),
			'Choose a valid package room.'                 => __( 'Choose a valid package room.', 'ludora-booking' ),
			'Choose a valid rental mode and opening date.' => __( 'Choose a valid rental mode and opening date.', 'ludora-booking' ),
			'Choose a valid shift.'                        => __( 'Choose a valid shift.', 'ludora-booking' ),
			'Choose an allowed duration and time increment.' => __( 'Choose an allowed duration and time increment.', 'ludora-booking' ),
			'Choose an allowed extra quantity.'            => __( 'Choose an allowed extra quantity.', 'ludora-booking' ),
			'Choose valid package extras.'                 => __( 'Choose valid package extras.', 'ludora-booking' ),
			'Closed dates must be valid YYYY-MM-DD dates.' => __( 'Closed dates must be valid YYYY-MM-DD dates.', 'ludora-booking' ),
			'Configuration is too large.'                  => __( 'Configuration is too large.', 'ludora-booking' ),
			'Duration and increment must be positive; maximum duration must cover the minimum.' => __( 'Duration and increment must be positive; maximum duration must cover the minimum.', 'ludora-booking' ),
			'Each shift needs key|Name|HH:MM|HH:MM.'       => __( 'Each shift needs key|Name|HH:MM|HH:MM.', 'ludora-booking' ),
			'Enter a valid JSON configuration object.'     => __( 'Enter a valid JSON configuration object.', 'ludora-booking' ),
			'Enter a valid responsible adult name and email.' => __( 'Enter a valid responsible adult name and email.', 'ludora-booking' ),
			'Enter the responsible adult’s name, valid email and accept the booking conditions.' => __( 'Enter the responsible adult’s name, valid email and accept the booking conditions.', 'ludora-booking' ),
			'Extra quantity exceeds inventory capacity.'   => __( 'Extra quantity exceeds inventory capacity.', 'ludora-booking' ),
			'Extras must be quantities indexed by ID.'     => __( 'Extras must be quantities indexed by ID.', 'ludora-booking' ),
			'Invalid attendee pricing basis.'              => __( 'Invalid attendee pricing basis.', 'ludora-booking' ),
			'Invalid blocked interval.'                    => __( 'Invalid blocked interval.', 'ludora-booking' ),
			'Invalid calendar date.'                       => __( 'Invalid calendar date.', 'ludora-booking' ),
			'Invalid currency.'                            => __( 'Invalid currency.', 'ludora-booking' ),
			'Invalid date filter.'                         => __( 'Invalid date filter.', 'ludora-booking' ),
			'Invalid guided configuration.'                => __( 'Invalid guided configuration.', 'ludora-booking' ),
			'Invalid opening interval.'                    => __( 'Invalid opening interval.', 'ludora-booking' ),
			'Invalid payment state.'                       => __( 'Invalid payment state.', 'ludora-booking' ),
			'Invalid retry key.'                           => __( 'Invalid retry key.', 'ludora-booking' ),
			'Invalid state transition.'                    => __( 'Invalid state transition.', 'ludora-booking' ),
			'Invalid timezone, currency or booking mode.'  => __( 'Invalid timezone, currency or booking mode.', 'ludora-booking' ),
			'Maximum quantity must cover the minimum.'     => __( 'Maximum quantity must cover the minimum.', 'ludora-booking' ),
			'Opening intervals must use HH:MM-HH:MM.'      => __( 'Opening intervals must use HH:MM-HH:MM.', 'ludora-booking' ),
			'Payment order does not match the reservation.' => __( 'Payment order does not match the reservation.', 'ludora-booking' ),
			'Permission denied.'                           => __( 'Permission denied.', 'ludora-booking' ),
			'Reservation not found.'                       => __( 'Reservation not found.', 'ludora-booking' ),
			'Reservation not found. Check your private access link.' => __( 'Reservation not found. Check your private access link.', 'ludora-booking' ),
			'Room is not available.'                       => __( 'Room is not available.', 'ludora-booking' ),
			'The linked payment order is unavailable. Contact the venue.' => __( 'The linked payment order is unavailable. Contact the venue.', 'ludora-booking' ),
			'The payment currency changed. Request a new reservation quote.' => __( 'The payment currency changed. Request a new reservation quote.', 'ludora-booking' ),
			'The payment total or linked order changed. Request a new quote.' => __( 'The payment total or linked order changed. Request a new quote.', 'ludora-booking' ),
			'The reservation checkout changed. Start again.' => __( 'The reservation checkout changed. Start again.', 'ludora-booking' ),
			'The reservation hold expired or changed. Request availability again.' => __( 'The reservation hold expired or changed. Request availability again.', 'ludora-booking' ),
			'The selected date is outside the booking window.' => __( 'The selected date is outside the booking window.', 'ludora-booking' ),
			'The selected interval is blocked.'            => __( 'The selected interval is blocked.', 'ludora-booking' ),
			'The selected interval is outside opening hours.' => __( 'The selected interval is outside opening hours.', 'ludora-booking' ),
			'The selected package requires its configured duration.' => __( 'The selected package requires its configured duration.', 'ludora-booking' ),
			'The selected package requires its configured room and rental mode.' => __( 'The selected package requires its configured room and rental mode.', 'ludora-booking' ),
			'The selected package requires its configured shift.' => __( 'The selected package requires its configured shift.', 'ludora-booking' ),
			'The venue is closed on this date.'            => __( 'The venue is closed on this date.', 'ludora-booking' ),
			'This extra is unavailable for this room or mode.' => __( 'This extra is unavailable for this room or mode.', 'ludora-booking' ),
			'This extra requires a single selection.'      => __( 'This extra requires a single selection.', 'ludora-booking' ),
			'This local time is ambiguous or does not exist because of a clock change.' => __( 'This local time is ambiguous or does not exist because of a clock change.', 'ludora-booking' ),
			'This package is not available.'               => __( 'This package is not available.', 'ludora-booking' ),
			'This reservation cannot be rescheduled.'      => __( 'This reservation cannot be rescheduled.', 'ludora-booking' ),
			'This retry key was already used for different details.' => __( 'This retry key was already used for different details.', 'ludora-booking' ),
			'This room or a limited extra is no longer available. Choose another time.' => __( 'This room or a limited extra is no longer available. Choose another time.', 'ludora-booking' ),
			'Times must use HH:MM with the end after the start on the same day.' => __( 'Times must use HH:MM with the end after the start on the same day.', 'ludora-booking' ),
			'Unable to prepare the reservation checkout.'  => __( 'Unable to prepare the reservation checkout.', 'ludora-booking' ),
			'Unknown extra.'                               => __( 'Unknown extra.', 'ludora-booking' ),
			'Unknown operation.'                           => __( 'Unknown operation.', 'ludora-booking' ),
			'Unsupported demo language.'                   => __( 'Unsupported demo language.', 'ludora-booking' ),
			'Use a valid date and HH:MM local time.'       => __( 'Use a valid date and HH:MM local time.', 'ludora-booking' ),
			'Use non-negative whole numbers within 100000000.' => __( 'Use non-negative whole numbers within 100000000.', 'ludora-booking' ),
			'WooCommerce payments are unavailable.'        => __( 'WooCommerce payments are unavailable.', 'ludora-booking' ),
			'Your price quote changed. Check availability and price again before sending your request.' => __( 'Your price quote changed. Check availability and price again before sending your request.', 'ludora-booking' ),
		);
		if ( isset( $messages[ $message ] ) ) {
			return $messages[ $message ];
		}
		// Guided fields already localize their exception; accept only known translations.
		if ( in_array( $message, $messages, true ) ) {
			return $message;
		}
		return __( 'We could not complete this request. Please try again or contact the venue.', 'ludora-booking' );
	}
	/**
	 * Label a booking or payment state without translating stored machine keys.
	 *
	 * @param string $state Booking or payment machine state.
	 * @return string
	 */
	public static function state( string $state ): string {
		$labels = array(
			'hold'      => __( 'Payment hold', 'ludora-booking' ),
			'pending'   => __( 'Pending', 'ludora-booking' ),
			'confirmed' => __( 'Confirmed', 'ludora-booking' ),
			'cancelled' => __( 'Cancelled', 'ludora-booking' ),
			'rejected'  => __( 'Rejected', 'ludora-booking' ),
			'expired'   => __( 'Expired', 'ludora-booking' ),
			'completed' => __( 'Completed', 'ludora-booking' ),
			'paid'      => __( 'Paid', 'ludora-booking' ),
			'failed'    => __( 'Failed', 'ludora-booking' ),
			'refunded'  => __( 'Refunded', 'ludora-booking' ),
		);
		return $labels[ $state ] ?? __( 'Unknown state', 'ludora-booking' );
	}
}
