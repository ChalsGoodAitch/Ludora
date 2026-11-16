<?php
/**
 * Deliver reservation notifications from the transactional outbox.
 *
 * @package Ludora\Booking
 */

namespace Ludora\Booking;

defined( 'ABSPATH' ) || exit;

/** Deliver reservation notifications from the transactional outbox. */
final class Mail {
	/**
	 * Serialize delivery attempts with a database advisory lock.
	 */
	public static function flush(): void {
		global $wpdb;
		$prefix = $wpdb->prefix . 'ludora_';
		// A DB advisory lock prevents two HTTP requests delivering the same outbox entry.
		$lock = 'ludora_mail_' . substr( hash( 'sha256', $prefix . DB_NAME ), 0, 32 );
		// Outbox delivery and advisory locking require current plugin-table state; cached results could duplicate mail.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) {
			return; }
		try {
			// Outbox delivery and advisory locking require current plugin-table state; cached results could duplicate mail.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE sent_at IS NULL AND attempts<5 ORDER BY id LIMIT 10', $prefix . 'outbox' ), ARRAY_A );
			foreach ( $rows as $message ) {
				$row = ( new Store() )->get( (int) $message['booking_id'] );
				if ( ! $row ) {
					continue; }
				$c               = json_decode( $row['customer'], true );
				$q               = json_decode( $row['snapshot'], true );
				$s               = Settings::get();
				$kind            = explode( ':', $message['event_key'] )[1];
				$effective_state = ( new Store() )->public_view( $row )['state'];
				if ( 'confirmed' === $kind && 'confirmed' !== $effective_state ) {
					// Suppress an obsolete queued confirmation after a later cancellation or completion.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update( $prefix . 'outbox', array( 'sent_at' => time() ), array( 'id' => $message['id'] ) );
					continue;
				}
				$labels  = array(
					'received'         => __( 'Booking request received', 'ludora-booking' ),
					'confirmed'        => __( 'Reservation confirmed', 'ludora-booking' ),
					'cancelled'        => __( 'Reservation cancelled', 'ludora-booking' ),
					'rejected'         => __( 'Booking request declined', 'ludora-booking' ),
					'expired'          => __( 'Reservation expired', 'ludora-booking' ),
					'completed'        => __( 'Reservation completed', 'ludora-booking' ),
					'payment_conflict' => __( 'Payment received — venue review required', 'ludora-booking' ),
				);
				$subject = ( $labels[ $kind ] ?? __( 'Reservation updated', 'ludora-booking' ) ) . ' · ' . $row['reference'];
				// Translators: %s is the reserved room title.
				$room_text = sprintf( __( 'Room: %s', 'ludora-booking' ), $q['lines'][0]['name'] );
				// Translators: %s is the formatted reservation total including its currency.
				$total_text = sprintf( __( 'Total: %s', 'ludora-booking' ), Settings::money( (int) $q['total'], $q ) );
				// Translators: 1: reservation state, 2: payment state.
				$state_text = sprintf( __( 'Reservation state: %1$s. Payment state: %2$s.', 'ludora-booking' ), Errors::state( $effective_state ), Errors::state( $row['payment_state'] ) );
				$text       = $subject . "\n\n" . $room_text . "\n" . wp_date( 'Y-m-d H:i', $row['start_utc'], new \DateTimeZone( $q['timezone'] ) ) . ' – ' . wp_date( 'H:i', $row['end_utc'], new \DateTimeZone( $q['timezone'] ) ) . ' (' . $q['timezone'] . ")\n" . $total_text . "\n" . $state_text;
				if ( 'received' === $kind ) {
					$text .= "\n" . __( 'This is a request receipt, not a payment receipt or confirmation. Pending requests expire unless the venue confirms them.', 'ludora-booking' ); }
				$token = Store::access_token( $row['idempotency'] );
				if ( hash_equals( $row['token_hash'], hash( 'sha256', $token ) ) ) {
					$text .= "\n\n" . __( 'Private booking status link (keep it secret):', 'ludora-booking' ) . "\n" . home_url( '/booking/#booking=' . $token ); }
				if ( in_array( $kind, array( 'cancelled', 'rejected' ), true ) ) {
					$text .= "\n" . __( 'Cancellation does not automatically refund a payment. Contact the venue about any refund.', 'ludora-booking' ); }
				// Record an attempt before dispatch. SMTP cannot promise exactly-once delivery after transport crashes.
				// Outbox delivery and advisory locking require current plugin-table state; cached results could duplicate mail.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET attempts=attempts+1 WHERE id=%d', $prefix . 'outbox', $message['id'] ) );
				$recipients = array_filter( array_unique( array( $c['email'] ?? '', $s['business_email'] ? $s['business_email'] : get_option( 'admin_email' ) ) ) );
				$ok         = true;
				$delivered  = json_decode( $message['delivered'] ?? '[]', true );
				if ( ! is_array( $delivered ) ) {
					$delivered = array(); }
				$sender  = is_email( $s['business_email'] ) ? $s['business_email'] : get_option( 'admin_email' );
				$headers = array( 'From: ' . sanitize_text_field( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . ' <' . sanitize_email( $sender ) . '>' );
				foreach ( $recipients as $recipient ) {
					$recipient_key = hash( 'sha256', strtolower( $recipient ) );
					if ( in_array( $recipient_key, $delivered, true ) ) {
						continue; }
					if ( wp_mail( $recipient, $subject, $text, $headers ) ) {
						$delivered[] = $recipient_key;
						// Per-recipient acknowledgement prevents a later business-recipient retry from duplicating a customer receipt.
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->update( $prefix . 'outbox', array( 'delivered' => wp_json_encode( $delivered ) ), array( 'id' => $message['id'] ) );
					} else {
						$ok = false; }
				}
				if ( $ok ) {
					// Outbox delivery and advisory locking require current plugin-table state; cached results could duplicate mail.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update( $prefix . 'outbox', array( 'sent_at' => time() ), array( 'id' => $message['id'] ) ); }
			}
		} finally {
			// Outbox delivery and advisory locking require current plugin-table state; cached results could duplicate mail.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
	}
}
