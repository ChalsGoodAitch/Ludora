<?php
/**
 * Theme-independent public booking blocks and form presentation.
 *
 * @package Ludora\Booking
 */

namespace Ludora\Booking;

defined( 'ABSPATH' ) || exit;

/** Theme-independent public booking blocks and form presentation. */
final class Frontend {
	/**
	 * Register plugin-owned dynamic blocks and their local production assets.
	 */
	public static function register(): void {
		$base = plugins_url( 'assets/', LUDORA_BOOKING_FILE );
		wp_register_style( 'ludora-booking', $base . 'booking.css', array(), LUDORA_BOOKING_VERSION );
		wp_register_script( 'ludora-booking', $base . 'booking.js', array(), LUDORA_BOOKING_VERSION, true );
		wp_register_script( 'ludora-booking-editor', $base . 'editor.js', array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor' ), LUDORA_BOOKING_VERSION, true );
		wp_set_script_translations( 'ludora-booking-editor', 'ludora-booking', dirname( LUDORA_BOOKING_FILE ) . '/languages' );
		foreach ( array( 'booking', 'search', 'rooms', 'business', 'packages', 'extras' ) as $name ) {
			register_block_type(
				'ludora/' . $name,
				array(
					'api_version'     => 3,
					'editor_script'   => 'ludora-booking-editor',
					'render_callback' => static fn() => self::render( $name ),
				)
			);
		}
		add_shortcode( 'ludora_booking', static fn() => self::render( 'booking' ) );
		add_filter( 'the_content', array( self::class, 'room_details' ) );
	}
	/**
	 * Load reservation assets only where a functional booking form is rendered.
	 */
	private static function assets(): void {
		wp_enqueue_style( 'ludora-booking' );
		wp_enqueue_script( 'ludora-booking' );
		wp_localize_script(
			'ludora-booking',
			'ludoraBooking',
			array(
				'api'      => esc_url_raw( rest_url( 'ludora/v1/' ) ),
				'timezone' => Settings::get()['timezone'],
				'states'   => array_combine( array( 'hold', 'pending', 'confirmed', 'cancelled', 'rejected', 'expired', 'completed', 'paid', 'failed', 'refunded' ), array_map( array( Errors::class, 'state' ), array( 'hold', 'pending', 'confirmed', 'cancelled', 'rejected', 'expired', 'completed', 'paid', 'failed', 'refunded' ) ) ),
				'text'     => array(
					'loading' => __( 'Checking…', 'ludora-booking' ),
					'quote'   => __( 'Check availability and price', 'ludora-booking' ),
					'submit'  => __( 'Send booking request', 'ludora-booking' ),
					'pay'     => __( 'Continue to secure payment', 'ludora-booking' ),
					'pending' => __( 'Request received — awaiting venue confirmation. Payment has not been received.', 'ludora-booking' ),
					'hold'    => __( 'Time held temporarily. Complete payment before the hold expires.', 'ludora-booking' ),
					'error'   => __( 'Connection failed. Please retry with the same details.', 'ludora-booking' ),
					'changed' => __( 'Details changed. Check availability and price again.', 'ludora-booking' ),
					'private' => __( 'Save this private booking link. Anyone with it can see your booking status.', 'ludora-booking' ),
					'status'  => __( 'Check booking status', 'ludora-booking' ),
					'total'   => __( 'Total', 'ludora-booking' ),
					'tax'     => __( 'Tax', 'ludora-booking' ),
					'net'     => __( 'Net rates shown; taxes are added below.', 'ludora-booking' ),
					'expires' => __( 'Expires at', 'ludora-booking' ),
					'review'  => __( 'Payment received — venue review required', 'ludora-booking' ),
				),
			)
		);
	}
	/**
	 * Render a public booking, collection or business-details block.
	 *
	 * @param string $type Registered functional block name.
	 * @return string
	 */
	public static function render( string $type ): string {
		if ( 'search' === $type ) {
			return self::search(); }
		if ( 'booking' === $type ) {
			return self::form(); }
		wp_enqueue_style( 'ludora-booking' );
		if ( 'business' === $type ) {
			$s = Settings::get();
			return '<address class="ludora-business"><p>' . esc_html( $s['address'] ) . '</p><p><a href="tel:' . esc_attr( preg_replace( '/[^+0-9]/', '', $s['phone'] ) ) . '">' . esc_html( $s['phone'] ) . '</a></p><p>' . nl2br( esc_html( $s['hours'] ) ) . '</p></address>';
		}
		$post_type = array(
			'rooms'    => 'ludora_room',
			'extras'   => 'ludora_extra',
			'packages' => 'ludora_package',
		)[ $type ];
		$posts     = get_posts(
			array(
				'post_type'   => $post_type,
				'numberposts' => 100,
				'post_status' => 'publish',
				'orderby'     => 'menu_order',
				'order'       => 'ASC',
			)
		);
		if ( ! $posts ) {
			return '<p>' . esc_html__( 'No items are available yet. Please contact the venue.', 'ludora-booking' ) . '</p>'; }
		$html = '<div class="ludora-cards">';
		foreach ( $posts as $post ) {
			$html .= '<article class="ludora-card">' . get_the_post_thumbnail( $post, 'large' ) . '<h3><a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( $post->post_title ) . '</a></h3><p>' . esc_html( get_the_excerpt( $post ) ) . '</p><a class="ludora-button" href="' . esc_url( add_query_arg( 'packages' === $type ? 'package' : 'room', in_array( $type, array( 'rooms', 'packages' ), true ) ? $post->ID : '', home_url( '/booking/' ) ) ) . '">' . esc_html__( 'Check dates and price', 'ludora-booking' ) . '</a></article>'; }
		return $html . '</div>';
	}
	/**
	 * Present the live reservation flow and legal setup state.
	 *
	 * @return string
	 */
	private static function form(): string {
		self::assets();
		$settings = Settings::get();
		if ( 'local' !== wp_get_environment_type() && 'publish' !== get_post_status( (int) $settings['terms_page'] ) ) {
			return '<p>' . esc_html__( 'Online booking will open after the venue publishes its booking conditions. Please contact the venue.', 'ludora-booking' ) . '</p>'; }
		$rooms = get_posts(
			array(
				'post_type'   => 'ludora_room',
				'numberposts' => 100,
				'post_status' => 'publish',
			)
		);
		if ( ! $rooms ) {
			return '<p>' . esc_html__( 'No rooms are open for booking. Please contact the venue.', 'ludora-booking' ) . '</p>'; }
		ob_start(); ?>
		<section class="ludora-app" data-package="<?php echo is_singular( 'ludora_package' ) ? (int) get_the_ID() : 0; ?>" aria-label="<?php esc_attr_e( 'Room reservation', 'ludora-booking' ); ?>">
		<p>
		<?php
		/* translators: %s: business IANA timezone. */
		echo esc_html( sprintf( __( 'All times use %s. Prices are calculated by the venue’s booking system.', 'ludora-booking' ), $settings['timezone'] ) );
		?>
		</p>
		<?php
		if ( WooCommerce::enabled() ) :
			?>
			<p><?php esc_html_e( 'Full payment only. One reservation per order. Starting a booking replaces all items currently in the cart.', 'ludora-booking' ); ?></p><?php endif; ?>
		<form class="ludora-form">
		<fieldset><legend><?php esc_html_e( '1. Choose your room and time', 'ludora-booking' ); ?></legend><div class="ludora-grid">
		<label><?php esc_html_e( 'Package (optional)', 'ludora-booking' ); ?><select name="package_id"><option value="0"><?php esc_html_e( 'Build your own celebration', 'ludora-booking' ); ?></option>
		<?php
		foreach ( get_posts(
			array(
				'post_type'   => 'ludora_package',
				'numberposts' => 100,
				'post_status' => 'publish',
			)
		) as $package ) :
			?>
								<option value="<?php echo (int) $package->ID; ?>" data-config="<?php echo esc_attr( wp_json_encode( Catalog::config( $package->ID ) ) ); ?>"><?php echo esc_html( $package->post_title ); ?></option><?php endforeach; ?></select></label>
		<label><?php esc_html_e( 'Room', 'ludora-booking' ); ?><select name="room_id" required>
		<?php
		foreach ( $rooms as $room ) :
			?>
			<option value="<?php echo (int) $room->ID; ?>" data-config="<?php echo esc_attr( wp_json_encode( Catalog::config( $room->ID ) ) ); ?>"><?php echo esc_html( $room->post_title ); ?></option><?php endforeach; ?></select></label>
		<label><?php esc_html_e( 'Date', 'ludora-booking' ); ?><input type="date" name="date" required></label>
		<label><?php esc_html_e( 'Rental mode', 'ludora-booking' ); ?><select name="mode"><option value="hourly"><?php esc_html_e( 'By the hour', 'ludora-booking' ); ?></option><option value="shift"><?php esc_html_e( 'Named shift', 'ludora-booking' ); ?></option><option value="day"><?php esc_html_e( 'Full business day', 'ludora-booking' ); ?></option></select></label>
		<label data-field="start"><?php esc_html_e( 'Start time', 'ludora-booking' ); ?><input type="time" name="start" value="10:00" step="1800"></label>
		<label data-field="duration"><?php esc_html_e( 'Duration (minutes)', 'ludora-booking' ); ?><input type="number" name="duration" min="30" max="1440" step="30" value="120"></label>
		<label data-field="shift" hidden><?php esc_html_e( 'Shift', 'ludora-booking' ); ?><select name="shift"></select></label>
		<label><?php esc_html_e( 'Children (count only)', 'ludora-booking' ); ?><input type="number" name="children" min="0" max="500" value="10" required></label>
		<label><?php esc_html_e( 'Adults', 'ludora-booking' ); ?><input type="number" name="adults" min="1" max="500" value="2" required></label>
		</div></fieldset>
		<fieldset><legend><?php esc_html_e( '2. Extras', 'ludora-booking' ); ?></legend><div class="ludora-grid"><?php foreach ( Catalog::extras() as $extra ) : ?>
		<label><?php echo esc_html( $extra['name'] ); ?><input type="number" data-extra="<?php echo (int) $extra['id']; ?>" min="0" max="<?php echo (int) ( 'quantity' === ( $extra['basis'] ?? 'fixed' ) ? ( $extra['max'] ?? 100 ) : 1 ); ?>" value="0"></label>
		<?php endforeach; ?></div><p><?php esc_html_e( 'Set a quantity to add an extra. The quote explains its pricing basis and includes any required extras.', 'ludora-booking' ); ?></p></fieldset>
		<button class="ludora-button" type="button" data-quote><?php esc_html_e( 'Check availability and price', 'ludora-booking' ); ?></button>
		<div class="ludora-feedback" role="status" aria-live="polite" tabindex="-1"></div><div class="ludora-summary"></div>
		<fieldset><legend><?php esc_html_e( '3. Responsible adult', 'ludora-booking' ); ?></legend><div class="ludora-grid">
		<label><?php esc_html_e( 'Full name', 'ludora-booking' ); ?><input name="name" autocomplete="name" maxlength="160" required></label>
		<label><?php esc_html_e( 'Email', 'ludora-booking' ); ?><input type="email" name="email" autocomplete="email" required></label>
		<label><?php esc_html_e( 'Phone (optional)', 'ludora-booking' ); ?><input type="tel" name="phone" autocomplete="tel" maxlength="60"></label></div>
		<label class="ludora-trap" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>
		<label class="ludora-check"><input type="checkbox" name="terms" required> <span><?php esc_html_e( 'I accept the booking and cancellation conditions.', 'ludora-booking' ); ?>
		<?php
		if ( 'publish' === get_post_status( (int) $settings['terms_page'] ) ) :
			?>
			<a href="<?php echo esc_url( get_permalink( (int) $settings['terms_page'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Read conditions (opens a new tab)', 'ludora-booking' ); ?></a><?php endif; ?></span></label>
		<?php
		if ( 'publish' !== get_post_status( (int) $settings['terms_page'] ) ) :
			?>
			<details><summary><?php esc_html_e( 'Local demo booking conditions', 'ludora-booking' ); ?></summary><p><?php esc_html_e( 'This local demonstration uses fictional rooms and prices. No real venue or live payment is provided. Manual requests await venue confirmation and expire according to settings. Payment holds expire separately. Cancelling a booking does not automatically refund payment. Publish your own reviewed conditions before accepting public reservations.', 'ludora-booking' ); ?></p></details><?php endif; ?></fieldset>
		<button class="ludora-button" type="submit" disabled><?php echo esc_html( WooCommerce::enabled() ? __( 'Continue to secure payment', 'ludora-booking' ) : __( 'Send booking request', 'ludora-booking' ) ); ?></button>
		</form><div class="ludora-result" tabindex="-1"></div><noscript><p><?php esc_html_e( 'Booking requires JavaScript. Please contact the venue to request a reservation.', 'ludora-booking' ); ?></p></noscript></section>
		<?php
		return ob_get_clean();
	}
	/**
	 * Render a lightweight room and date form targeting the booking page.
	 *
	 * @return string
	 */
	private static function search(): string {
		wp_enqueue_style( 'ludora-booking' );
		$html = '<form class="ludora-search" action="' . esc_url( home_url( '/booking/' ) ) . '" method="get"><label>' . esc_html__( 'Room', 'ludora-booking' ) . '<select name="room">';
		foreach ( get_posts(
			array(
				'post_type'   => 'ludora_room',
				'numberposts' => 100,
				'post_status' => 'publish',
			)
		) as $room ) {
			$html .= '<option value="' . (int) $room->ID . '">' . esc_html( $room->post_title ) . '</option>'; }
		return $html . '</select></label><label>' . esc_html__( 'Date', 'ludora-booking' ) . '<input type="date" name="date" required min="' . esc_attr( wp_date( 'Y-m-d', null, new \DateTimeZone( Settings::get()['timezone'] ) ) ) . '"></label><button class="ludora-button">' . esc_html__( 'Find your celebration', 'ludora-booking' ) . '</button></form>';
	}
	/**
	 * Append editable capacity and house rules to individual room content.
	 *
	 * @param string $content Native room post content.
	 * @return string
	 */
	public static function room_details( string $content ): string {
		if ( ! is_singular( 'ludora_room' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content; }
		$c = Catalog::config( get_the_ID() );
		/* translators: 1: child capacity, 2: adult capacity. */
		$content  .= '<section class="ludora-room-details"><h2>' . esc_html__( 'Room details', 'ludora-booking' ) . '</h2><p>' . esc_html( sprintf( __( 'Capacity: %1$d children and %2$d adults.', 'ludora-booking' ), $c['capacity_children'] ?? 0, $c['capacity_adults'] ?? 0 ) ) . '</p><p>' . esc_html( $c['equipment'] ?? '' ) . '</p><p>' . esc_html( $c['rules'] ?? '' ) . '</p><p>' . esc_html__( 'The final price depends on date, mode, duration and extras. Check live availability for a full quote.', 'ludora-booking' ) . '</p><a class="wp-element-button" href="' . esc_url( add_query_arg( 'room', get_the_ID(), home_url( '/booking/' ) ) ) . '">' . esc_html__( 'Check availability', 'ludora-booking' ) . '</a></section>';
		$settings  = WooCommerce::authority( Settings::get() );
		$precision = (int) $settings['precision'];
		$scale     = 10 ** $precision;
		$rates     = '<h3>' . esc_html__( 'Indicative base rates', 'ludora-booking' ) . '</h3><p>' . esc_html__( 'Base prices before tax and extras. Date and weekday exceptions may change these amounts; the live quote is authoritative.', 'ludora-booking' ) . '</p><dl>';
		foreach ( array(
			'hourly' => __( 'By the hour', 'ludora-booking' ),
			'shift'  => __( 'Named shift', 'ludora-booking' ),
			'day'    => __( 'Full business day', 'ludora-booking' ),
		) as $mode => $label ) {
			$minor  = (int) ( $c['rates'][ $mode ] ?? 0 );
			$amount = (string) intdiv( $minor, $scale );
			if ( $precision ) {
				$amount .= '.' . str_pad( (string) ( $minor % $scale ), $precision, '0', STR_PAD_LEFT );
			}
			$rates .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $amount . ' ' . $settings['currency'] ) . '</dd>';
		}
		$rates .= '</dl>';
		return substr_replace( $content, $rates, -strlen( '</section>' ), 0 );
	}
}
