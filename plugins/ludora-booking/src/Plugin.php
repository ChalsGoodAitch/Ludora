<?php
/**
 * Connect booking services to WordPress hooks.
 *
 * @package Ludora\Booking
 */

namespace Ludora\Booking;

defined( 'ABSPATH' ) || exit;

/** Connect booking services to WordPress hooks. */
final class Plugin {
	/**
	 * Register the plugin hooks and initialize integrations.
	 */
	public static function boot(): void {
		if ( (int) get_option( 'ludora_schema_version' ) < 2 ) {
			Installer::install(); }
		add_action( 'init', array( Catalog::class, 'register' ) );
		add_action( 'init', array( Frontend::class, 'register' ) );
		add_action( 'rest_api_init', array( Api::class, 'register' ) );
		add_action( 'admin_menu', array( Admin::class, 'menu' ) );
		add_action(
			'admin_enqueue_scripts',
			static function () {
				$screen = get_current_screen();
				if ( $screen && ( 'toplevel_page_ludora' === $screen->id || in_array( $screen->post_type, array( 'ludora_room', 'ludora_extra', 'ludora_package' ), true ) ) ) {
					wp_enqueue_script( 'ludora-admin', plugins_url( 'assets/admin.js', LUDORA_BOOKING_FILE ), array(), LUDORA_BOOKING_VERSION, true );
				} }
		);
		add_action(
			'admin_notices',
			static function () {
				$error = get_transient( 'ludora_error_' . get_current_user_id() );
				if ( $error && current_user_can( 'manage_ludora' ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
					delete_transient( 'ludora_error_' . get_current_user_id() );
				} }
		);
		add_action( 'add_meta_boxes', array( Admin::class, 'boxes' ) );
		add_action( 'save_post', array( Admin::class, 'save_config' ), 10, 2 );
		add_action( 'admin_post_ludora_action', array( Admin::class, 'action' ) );
		add_action( 'ludora_maintenance', array( Privacy::class, 'maintenance' ) );
		add_action( 'shutdown', array( Mail::class, 'flush' ) );
		add_action(
			'phpmailer_init',
			static function ( $mailer ) {
				if ( defined( 'LUDORA_MAIL_HOST' ) && 'local' === wp_get_environment_type() ) {
					$mailer->isSMTP();
					// PHPMailer exposes these externally defined property names.
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$mailer->Host = LUDORA_MAIL_HOST;
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$mailer->Port = 1025;
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$mailer->SMTPAuth = false; }
			}
		);
		Privacy::boot();
		WooCommerce::boot();
	}
}
