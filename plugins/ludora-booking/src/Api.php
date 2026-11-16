<?php
/**
 * Cookie-bound booking REST endpoints.
 *
 * @package LudoraBooking
 */

namespace Ludora\Booking;

defined( 'ABSPATH' ) || exit;

/** Booking REST controller with domain-owned mutations. */
final class Api {
	/**
	 * Register same-origin booking session and reservation endpoints.
	 */
	public static function register(): void {
		register_rest_route(
			'ludora/v1',
			'/session',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( self::class, 'origin' ),
				'callback'            => array( self::class, 'session' ),
			)
		);
		foreach ( array( 'quote', 'reserve', 'status' ) as $action ) {
			register_rest_route(
				'ludora/v1',
				'/' . $action,
				array(
					'methods'             => 'POST',
					'permission_callback' => array( self::class, 'authorize' ),
					'callback'            => static fn( $request ) => self::handle( $action, $request ),
					'args'                => array(
						'request'         => array(
							'type'     => 'object',
							'required' => 'status' !== $action,
						),
						'customer'        => array( 'type' => 'object' ),
						'quote_signature' => array(
							'type'      => 'string',
							'required'  => 'reserve' === $action,
							'minLength' => 64,
							'maxLength' => 64,
							'pattern'   => '^[a-f0-9]{64}$',
						),
						'key'             => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
						'token'           => array(
							'type'      => 'string',
							'maxLength' => 64,
						),
					),
				)
			);
		}
	}
	/**
	 * Reject explicit cross-origin browser requests.
	 */
	public static function origin() {
		$origin = get_http_origin();
		if ( $origin && ! in_array( $origin, array( untrailingslashit( home_url() ), untrailingslashit( site_url() ) ), true ) ) {
			return new \WP_Error( 'ludora_origin', __( 'Request origin is not allowed.', 'ludora-booking' ), array( 'status' => 403 ) ); }
		return true;
	}
	/**
	 * Create a short-lived HttpOnly session and separate CSRF secret.
	 */
	public static function session() {
		if ( ! self::rate( 'session', 40 ) ) {
			return new \WP_Error( 'ludora_rate', __( 'Too many requests. Please try again later.', 'ludora-booking' ), array( 'status' => 429 ) ); }
		$cookie  = isset( $_COOKIE['ludora_session'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['ludora_session'] ?? '' ) ) : '';
		$session = preg_match( '/^[a-f0-9]{64}$/D', $cookie ) ? get_transient( 'ludora_s_' . hash( 'sha256', $cookie ) ) : false;
		if ( ! $session ) {
			$cookie  = bin2hex( random_bytes( 32 ) );
			$session = bin2hex( random_bytes( 32 ) );
			set_transient( 'ludora_s_' . hash( 'sha256', $cookie ), $session, HOUR_IN_SECONDS );
			setcookie(
				'ludora_session',
				$cookie,
				array(
					'expires'  => time() + HOUR_IN_SECONDS,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Strict',
				)
			);
		}
		$response = new \WP_REST_Response( array( 'csrf' => $session ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
	/**
	 * Validate the cookie-bound CSRF secret and request rate limit.
	 *
	 * @param \WP_REST_Request $request Incoming REST request.
	 */
	public static function authorize( $request ) {
		$origin = self::origin();
		if ( is_wp_error( $origin ) ) {
			return $origin; }
		$cookie  = isset( $_COOKIE['ludora_session'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['ludora_session'] ?? '' ) ) : '';
		$session = preg_match( '/^[a-f0-9]{64}$/D', $cookie ) ? get_transient( 'ludora_s_' . hash( 'sha256', $cookie ) ) : false;
		if ( ! $session || ! hash_equals( $session, (string) $request->get_header( 'X-Ludora-CSRF' ) ) ) {
			return new \WP_Error( 'ludora_session', __( 'Your session expired. Reload and try again.', 'ludora-booking' ), array( 'status' => 403 ) ); }
		return self::rate( 'api', 100 ) ? true : new \WP_Error( 'ludora_rate', __( 'Too many requests. Please try again later.', 'ludora-booking' ), array( 'status' => 429 ) );
	}
	/**
	 * Increment a fresh atomic fixed-window counter and fail closed.
	 *
	 * @param string $scope Rate-limit operation scope.
	 * @param int    $limit Maximum requests in this window.
	 */
	private static function rate( string $scope, int $limit ): bool {
		// Atomic option insertion provides a fixed-window counter with no remote service.
		$ip  = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
		$key = 'ludora_rate_' . hash_hmac( 'sha256', $ip . $scope . floor( time() / 600 ), wp_salt() );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic counter mutation intentionally bypasses option caches.
		if ( false === $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,'1','off') ON DUPLICATE KEY UPDATE option_value=CAST(option_value AS UNSIGNED)+1", $key ) ) ) {
			return false; }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fresh counter value is required to enforce the concurrent request limit.
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $key ) );
		return ! $wpdb->last_error && $count >= 1 && $count <= $limit;
	}
	/**
	 * Validate request data and invoke the transactional domain service.
	 *
	 * @param string           $action Endpoint action.
	 * @param \WP_REST_Request $input Incoming REST request.
	 * @throws \DomainException Caught and returned as a validation response.
	 */
	private static function handle( string $action, $input ) {
		try {
			$store = new Store();
			if ( 'status' === $action ) {
				$row = $store->by_token( (string) $input['token'] );
				if ( ! $row ) {
					throw new \DomainException( 'Reservation not found. Check your private access link.' ); }
				$result = $store->public_view( $row );
			} else {
				$request = Catalog::request( (array) $input['request'] );
				if ( 'quote' === $action ) {
					$result              = $store->transaction( fn() => $store->quote( $request ) );
					$result['signature'] = Store::quote_signature( $result ); } else {
					$settings = Settings::get();
					if ( 'local' !== wp_get_environment_type() && 'publish' !== get_post_status( (int) $settings['terms_page'] ) ) {
						throw new \DomainException( 'Online booking will open after the venue publishes its booking conditions. Please contact the venue.' ); }
					$raw      = (array) $input['customer'];
					$customer = array(
						'name'          => sanitize_text_field( $raw['name'] ?? '' ),
						'email'         => sanitize_email( $raw['email'] ?? '' ),
						'phone'         => sanitize_text_field( $raw['phone'] ?? '' ),
						'terms'         => true,
						'terms_page'    => (int) $settings['terms_page'],
						'terms_version' => $settings['terms_page'] ? hash( 'sha256', (string) get_post_field( 'post_content', (int) $settings['terms_page'] ) ) : 'local-demo',
					);
					if ( '' === $customer['name'] || strlen( $customer['name'] ) > 160 || ! is_email( $customer['email'] ) || strlen( $customer['phone'] ) > 60 || true !== ( $raw['terms'] ?? false ) || ! empty( $raw['website'] ) ) {
						throw new \DomainException( 'Enter the responsible adult’s name, valid email and accept the booking conditions.' ); }
					$key = (string) $input['key'];
					if ( ! preg_match( '/^[a-zA-Z0-9-]{16,80}$/D', $key ) ) {
						throw new \DomainException( 'Invalid retry key.' ); }
					$session         = sanitize_text_field( wp_unslash( $_COOKIE['ludora_session'] ?? '' ) );
					$idempotency     = hash_hmac( 'sha256', $session . $key, wp_salt( 'auth' ) );
					$token           = Store::access_token( $idempotency );
					$pay             = WooCommerce::enabled();
					$row             = $store->create( $request, $customer, $idempotency, $token, $pay, (string) $input['quote_signature'] );
					$result          = $store->public_view( $row );
					$result['token'] = $token;
					if ( $pay ) {
						$result['checkout_url'] = WooCommerce::checkout( $row ); }
					}
			}
			$response = new \WP_REST_Response( $result );
			$response->header( 'Cache-Control', 'no-store, private' );
			return $response;
		} catch ( \DomainException | \InvalidArgumentException $e ) {
			return new \WP_Error( 'ludora_validation', Errors::message( $e->getMessage() ), array( 'status' => 409 ) ); } catch ( \Throwable $e ) {
			return new \WP_Error( 'ludora_error', __( 'We could not complete this request. Please try again or contact the venue.', 'ludora-booking' ), array( 'status' => 500 ) ); }
	}
}
