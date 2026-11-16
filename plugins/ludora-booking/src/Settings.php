<?php
/**
 * Provide business configuration and minor-unit money formatting.
 *
 * @package Ludora\Booking
 */

namespace Ludora\Booking;

defined( 'ABSPATH' ) || exit;

/** Provide business configuration and minor-unit money formatting. */
final class Settings {
	/**
	 * Return the initial business configuration.
	 *
	 * @return array Resolved values.
	 */
	public static function defaults(): array {
		return array(
			'timezone'         => 'Europe/Madrid',
			'currency'         => 'EUR',
			'precision'        => 2,
			'hold_minutes'     => 15,
			'pending_hours'    => 48,
			'retention_days'   => 365,
			'mode'             => 'manual',
			'terms_page'       => 0,
			'business_email'   => '',
			'phone'            => '',
			'address'          => '',
			'hours'            => '',
			'tax_basis_points' => 0,
			'purge'            => false,
		);
	}
	/**
	 * Merge persisted business settings with defaults.
	 *
	 * @return array Resolved values.
	 */
	public static function get(): array {
		return wp_parse_args( get_option( 'ludora_settings', array() ), self::defaults() ); }
	/**
	 * Format integer minor units using the recorded currency precision.
	 *
	 * @param int        $amount Amount in integer minor units.
	 * @param array|null $snapshot Recorded currency and precision, or current settings.
	 * @return string Formatted value.
	 */
	public static function money( int $amount, ?array $snapshot = null ): string {
		$s = $snapshot ?? self::get();
		return number_format_i18n( $amount / ( 10 ** (int) $s['precision'] ), (int) $s['precision'] ) . ' ' . $s['currency'];
	}
}
