<?php
/**
 * Plugin Name: Ludora Booking
 * Description: Exclusive room reservations and optional WooCommerce payments for celebration venues.
 * Version: 0.1.0
 * Requires at least: 7.1.4
 * Requires PHP: 8.4
 * Author: Ludora contributors
 * License: GPL-2.0-or-later
 * Text Domain: ludora-booking
 * Domain Path: /languages
 *
 * @package Ludora\Booking
 */

defined( 'ABSPATH' ) || exit;

define( 'LUDORA_BOOKING_FILE', __FILE__ );
define( 'LUDORA_BOOKING_VERSION', '0.1.0' );
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Ludora\\Booking\\';
		if ( str_starts_with( $class_name, $prefix ) ) {
			$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
			if ( is_file( $file ) ) {
				require_once $file;
			}
		}
	}
);
register_activation_hook( __FILE__, array( 'Ludora\\Booking\\Installer', 'install' ) );
register_deactivation_hook( __FILE__, array( 'Ludora\\Booking\\Installer', 'deactivate' ) );
add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'ludora-booking', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		Ludora\Booking\Plugin::boot();
	}
);
