<?php
/**
 * Render and authorize reservation administration.
 *
 * @package Ludora\Booking
 */

namespace Ludora\Booking;

defined( 'ABSPATH' ) || exit;

/** Render and authorize reservation administration. */
final class Admin {
	/**
	 * Register reservation administration menus.
	 */
	public static function menu(): void {
		add_menu_page( 'Ludora', 'Ludora', 'manage_ludora', 'ludora', array( self::class, 'page' ), 'dashicons-calendar-alt', 26 );
		add_submenu_page( 'ludora', __( 'Settings', 'ludora-booking' ), __( 'Settings', 'ludora-booking' ), 'manage_ludora', 'ludora-settings', array( self::class, 'settings' ) );
		add_submenu_page( 'ludora', __( 'Demo import', 'ludora-booking' ), __( 'Demo import', 'ludora-booking' ), 'manage_ludora', 'ludora-demo', array( Demo::class, 'page' ) );
	}
	/**
	 * Require the reservation management capability.
	 */
	private static function permitted(): void {
		if ( ! current_user_can( 'manage_ludora' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage reservations.', 'ludora-booking' ), '', array( 'response' => 403 ) ); } }
	/**
	 * Register catalog configuration meta boxes.
	 */
	public static function boxes(): void {
		foreach ( array( 'ludora_room', 'ludora_extra', 'ludora_package' ) as $type ) {
			add_meta_box( 'ludora-config', __( 'Booking configuration', 'ludora-booking' ), array( self::class, 'box' ), $type, 'normal', 'high' ); }
	}
	/**
	 * Render the configuration editor.
	 *
	 * @param \WP_Post $post Catalog post being edited.
	 */
	public static function box( $post ): void {
		if ( ! current_user_can( 'manage_ludora' ) ) {
			return; }
		wp_nonce_field( 'ludora_config', 'ludora_config_nonce' );
		$c = Catalog::config( $post->ID );
		if ( ! $c ) {
			$c = Demo::configuration( $post->post_type ); }
		ConfigurationForm::render( $post, $c );
	}
	/**
	 * Validate and persist catalog configuration under the catalog lock.
	 *
	 * @param int      $id Catalog or reservation identifier.
	 * @param \WP_Post $post Catalog post being edited.
	 */
	public static function save_config( int $id, $post ): void {
		if ( ! in_array( $post->post_type, array( 'ludora_room', 'ludora_extra', 'ludora_package' ), true ) || wp_is_post_revision( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! isset( $_POST['ludora_config_nonce'], $_POST['ludora_config'] ) ) {
			return; }
		if ( ! current_user_can( 'manage_ludora' ) || ! current_user_can( 'edit_post', $id ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ludora_config_nonce'] ) ), 'ludora_config' ) ) {
			return; }
		try {
			$value = ConfigurationForm::normalize( wp_unslash( $_POST ), $post->post_type );
		} catch ( \DomainException $e ) {
			set_transient( 'ludora_error_' . get_current_user_id(), Errors::message( $e->getMessage() ), 120 );
			return; }
		( new Store() )->transaction(
			static function () use ( $id, $value ) {
				update_post_meta( $id, '_ludora_config', $value );
			}
		);
	}
	/**
	 * Open an authorized administration screen.
	 *
	 * @param string $title Screen title.
	 */
	private static function start( string $title ): void {
		self::permitted();
		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';
		$error = get_transient( 'ludora_error_' . get_current_user_id() );
		if ( $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
			delete_transient( 'ludora_error_' . get_current_user_id() ); }
	}
	/**
	 * Render the reservation list and creation form.
	 */
	public static function page(): void {
		self::start( __( 'Reservations', 'ludora-booking' ) );
		wp_enqueue_script( 'ludora-admin', plugins_url( 'assets/admin.js', LUDORA_BOOKING_FILE ), array(), LUDORA_BOOKING_VERSION, true );
		$store = new Store();
		// Read-only view selection; no state changes occur for GET requests.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id  = isset( $_GET['booking'] ) ? absint( $_GET['booking'] ) : 0;
		$row = $id ? $store->get( $id ) : null;
		if ( $row ) {
			self::detail( $row );
			echo '</div>';
			return; }
		// Read-only list filters do not perform an administrative action.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$filters = array(
			'room_id' => isset( $_GET['room_id'] ) ? absint( $_GET['room_id'] ) : 0,
			'state'   => isset( $_GET['state'] ) ? sanitize_key( $_GET['state'] ) : '',
			'date'    => isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		echo '<form method="get"><input type="hidden" name="page" value="ludora"><p><label>' . esc_html__( 'Date', 'ludora-booking' ) . ' <input type="date" name="date" value="' . esc_attr( $filters['date'] ) . '"></label></p>';
		self::room_select( $filters['room_id'], true );
		echo '<p><label>' . esc_html__( 'State', 'ludora-booking' ) . ' <select name="state"><option value="">' . esc_html__( 'All', 'ludora-booking' ) . '</option>';
		foreach ( array( 'hold', 'pending', 'confirmed', 'cancelled', 'rejected', 'expired', 'completed' ) as $state ) {
			echo '<option value="' . esc_attr( $state ) . '" ' . selected( $filters['state'], $state, false ) . '>' . esc_html( Errors::state( $state ) ) . '</option>'; }
		echo '</select></label> <button class="button">' . esc_html__( 'Filter', 'ludora-booking' ) . '</button></p></form>';
		try {
			// Read-only result paging does not modify reservation data.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$result_page = max( 1, absint( $_GET['bookings_page'] ?? 1 ) );
			$rows        = $store->listing( $filters, 1000, ( $result_page - 1 ) * 1000 );
		} catch ( \DomainException $e ) {
			echo '<div class="notice notice-error"><p>' . esc_html( Errors::message( $e->getMessage() ) ) . '</p></div>';
			$rows = array();
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Reference', 'ludora-booking' ) . '</th><th>' . esc_html__( 'Room', 'ludora-booking' ) . '</th><th>' . esc_html__( 'Local time', 'ludora-booking' ) . '</th><th>' . esc_html__( 'State / payment', 'ludora-booking' ) . '</th><th>' . esc_html__( 'Total', 'ludora-booking' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$q    = json_decode( $row['snapshot'], true );
			$view = $store->public_view( $row );
			echo '<tr><td><a href="' . esc_url(
				add_query_arg(
					array(
						'page'    => 'ludora',
						'booking' => $row['id'],
					),
					admin_url( 'admin.php' )
				)
			) . '">' . esc_html( $row['reference'] ) . '</a></td><td>' . esc_html( get_the_title( (int) $row['room_id'] ) ) . '</td><td>' . esc_html( self::date( $row['start_utc'] ) . ' — ' . self::date( $row['end_utc'] ) ) . '</td><td>' . esc_html( Errors::state( $view['state'] ) . ' / ' . Errors::state( $row['payment_state'] ) ) . '</td><td>' . esc_html( Settings::money( (int) $q['total'], $q ) ) . '</td></tr>'; }
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No reservations match these filters.', 'ludora-booking' ) . '</td></tr>';
		} echo '</tbody></table>';
		echo '<p>';
		foreach ( array(
			-1 => __( 'Previous results', 'ludora-booking' ),
			1  => __( 'Next results', 'ludora-booking' ),
		) as $direction => $label ) {
			if ( ( -1 === $direction && $result_page > 1 ) || ( 1 === $direction && count( $rows ) === 1000 ) ) {
				echo '<a class="button" href="' . esc_url(
					add_query_arg(
						array_merge(
							$filters,
							array(
								'page'          => 'ludora',
								'bookings_page' => $result_page + $direction,
							)
						),
						admin_url( 'admin.php' )
					)
				) . '">' . esc_html( $label ) . '</a> ';
			}
		}
		echo '</p>';
		self::calendar( $store, $filters );
		self::action_form( 'export' );
		echo '<button class="button">' . esc_html__( 'Export filtered CSV', 'ludora-booking' ) . '</button>';
		foreach ( $filters as $key => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
		} echo '</form>';
		echo '<h2>' . esc_html__( 'Create a manual reservation', 'ludora-booking' ) . '</h2>';
		self::action_form( 'create' );
		self::input( 'name', __( 'Responsible adult name', 'ludora-booking' ) );
		self::input( 'email', __( 'Email', 'ludora-booking' ), '', 'email' );
		self::request_fields();
		echo '<p><button class="button button-primary">' . esc_html__( 'Create pending request', 'ludora-booking' ) . '</button></p></form></div>';
	}
	/**
	 * Show actual reservations in a business-local month grid.
	 *
	 * @param Store $store Reservation reader.
	 * @param array $filters Current room and state filters.
	 */
	private static function calendar( Store $store, array $filters ): void {
		$zone = new \DateTimeZone( Settings::get()['timezone'] );
		$now  = new \DateTimeImmutable( 'now', $zone );
		// Read-only calendar selection does not change reservation data.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['calendar_month'] ) ? sanitize_text_field( wp_unslash( $_GET['calendar_month'] ) ) : substr( $filters['date'] ? $filters['date'] : $now->format( 'Y-m-d' ), 0, 7 );
		$month     = \DateTimeImmutable::createFromFormat( '!Y-m-d', $requested . '-01', $zone );
		if ( ! $month || $month->format( 'Y-m' ) !== $requested ) {
			$month = $now->modify( 'first day of this month' )->setTime( 0, 0 );
		}
		$base = array(
			'page'    => 'ludora',
			'room_id' => $filters['room_id'],
			'state'   => $filters['state'],
		);
		echo '<h2>' . esc_html__( 'Reservation calendar', 'ludora-booking' ) . '</h2><p>' . esc_html__( 'Shows actual reservation start dates in the business timezone, with the selected room and state filters. Empty days do not imply availability.', 'ludora-booking' ) . '</p><p>';
		foreach ( array(
			'-1 month' => __( 'Previous month', 'ludora-booking' ),
			'+1 month' => __( 'Next month', 'ludora-booking' ),
		) as $move => $label ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( $base, array( 'calendar_month' => $month->modify( $move )->format( 'Y-m' ) ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a> ';
		}
		echo '</p><div style="overflow-x:auto"><table class="widefat ludora-admin-calendar" style="table-layout:fixed;min-width:640px"><caption style="text-align:start;font-size:1.3em;font-weight:600;padding:12px 0">' . esc_html( wp_date( 'F Y', $month->getTimestamp(), $zone ) ) . '</caption><thead><tr>';
		$week_start = (int) get_option( 'start_of_week', 1 );
		$weekday    = array( __( 'Sunday', 'ludora-booking' ), __( 'Monday', 'ludora-booking' ), __( 'Tuesday', 'ludora-booking' ), __( 'Wednesday', 'ludora-booking' ), __( 'Thursday', 'ludora-booking' ), __( 'Friday', 'ludora-booking' ), __( 'Saturday', 'ludora-booking' ) );
		for ( $i = 0; $i < 7; $i++ ) {
			echo '<th scope="col">' . esc_html( $weekday[ ( $week_start + $i ) % 7 ] ) . '</th>';
		}
		echo '</tr></thead><tbody><tr>';
		$offset = ( (int) $month->format( 'w' ) - $week_start + 7 ) % 7;
		for ( $i = 0; $i < $offset; $i++ ) {
			echo '<td></td>';
		}
		$days = (int) $month->format( 't' );
		for ( $day = 1; $day <= $days; $day++ ) {
			$date        = $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'm' ), $day )->format( 'Y-m-d' );
			$day_filters = array_merge( $filters, array( 'date' => $date ) );
			$rows        = $store->listing( $day_filters );
			echo '<td style="height:100px;vertical-align:top;overflow-wrap:anywhere"><a href="' . esc_url(
				add_query_arg(
					array_merge(
						$base,
						array(
							'date'           => $date,
							'calendar_month' => $month->format( 'Y-m' ),
						)
					),
					admin_url( 'admin.php' )
				)
			) . '" aria-label="' . esc_attr( wp_date( 'F j, Y', ( new \DateTimeImmutable( $date . ' 12:00:00', $zone ) )->getTimestamp(), $zone ) ) . '"><strong>' . (int) $day . '</strong></a>';
			if ( $rows ) {
				echo '<ul style="margin-block:8px">';
				foreach ( array_reverse( $rows ) as $row ) {
					$state = $store->public_view( $row )['state'];
					echo '<li><a href="' . esc_url(
						add_query_arg(
							array(
								'page'    => 'ludora',
								'booking' => $row['id'],
							),
							admin_url( 'admin.php' )
						)
					) . '">' . esc_html( wp_date( 'H:i', (int) $row['start_utc'], $zone ) . ' ' . get_the_title( (int) $row['room_id'] ) . ' · ' . $row['reference'] ) . '</a><br><small>' . esc_html( Errors::state( $state ) ) . '</small></li>';
				}
				echo '</ul>';
			}
			echo '</td>';
			if ( 0 === ( $day + $offset ) % 7 && $day < $days ) {
				echo '</tr><tr>';
			}
		}
		$remaining = ( 7 - ( $days + $offset ) % 7 ) % 7;
		for ( $i = 0; $i < $remaining; $i++ ) {
			echo '<td></td>';
		}
		echo '</tr></tbody></table></div><p>' . esc_html__( 'Up to 1,000 reservations are shown per day. Use room and state filters to narrow busy days.', 'ludora-booking' ) . '</p>';
	}

	/**
	 * Format a UTC timestamp in the business timezone.
	 *
	 * @param int $time UTC Unix timestamp.
	 * @return string Formatted value.
	 */
	private static function date( $time ): string {
		return wp_date( 'Y-m-d H:i', (int) $time, new \DateTimeZone( Settings::get()['timezone'] ) ); }
	/**
	 * Render an escaped required form input.
	 *
	 * @param string $name Input field name.
	 * @param string $label Visible input label.
	 * @param string $value Initial field value.
	 * @param string $type HTML input type.
	 */
	private static function input( string $name, string $label, string $value = '', string $type = 'text' ): void {
		echo '<p><label>' . esc_html( $label ) . '<br><input class="regular-text" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . ( 'number' === $type ? ' min="0" step="1"' : '' ) . ' required></label></p>'; }
	/**
	 * Render reservation interval and attendance fields.
	 *
	 * @param array $r Existing reservation request.
	 */
	private static function request_fields( array $r = array() ): void {
		$rooms   = self::rooms();
		$room_id = (int) ( $r['room_id'] ?? ( $rooms[0]->ID ?? 0 ) );
		echo '<fieldset class="ludora-admin-request"><legend><strong>' . esc_html__( 'Room, date and attendance', 'ludora-booking' ) . '</strong></legend><p>' . esc_html__( 'All times use the business timezone. Availability and the final price are checked when you submit.', 'ludora-booking' ) . '</p>';
		self::room_select( $room_id );
		echo '<p><label>' . esc_html__( 'Rental mode', 'ludora-booking' ) . '<br><select name="mode">';
		foreach ( array(
			'hourly' => __( 'Hourly rental', 'ludora-booking' ),
			'shift'  => __( 'Shift rental', 'ludora-booking' ),
			'day'    => __( 'Full opening day', 'ludora-booking' ),
		) as $mode => $label ) {
			echo '<option value="' . esc_attr( $mode ) . '" ' . selected( $r['mode'] ?? 'hourly', $mode, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p>';
		self::input( 'date', __( 'Date', 'ludora-booking' ), $r['date'] ?? '', 'date' );
		echo '<div data-ludora-mode="hourly">';
		self::input( 'start', __( 'Start time', 'ludora-booking' ), $r['start'] ?? '10:00', 'time' );
		self::input( 'duration', __( 'Duration in minutes', 'ludora-booking' ), (string) ( $r['duration'] ?? 120 ), 'number' );
		echo '</div><p data-ludora-mode="shift"><label>' . esc_html__( 'Shift', 'ludora-booking' ) . '<br><select name="shift"><option value="">' . esc_html__( 'Choose a shift for your room', 'ludora-booking' ) . '</option>';
		foreach ( $rooms as $room ) {
			echo '<optgroup label="' . esc_attr( $room->post_title ) . '" data-room="' . (int) $room->ID . '">';
			foreach ( Catalog::config( $room->ID )['shifts'] ?? array() as $key => $shift ) {
				echo '<option value="' . esc_attr( $key ) . '" data-room="' . (int) $room->ID . '" ' . selected( $room_id === (int) $room->ID && ( $r['shift'] ?? '' ) === (string) $key, true, false ) . '>' . esc_html( ( $shift['name'] ?? $key ) . ' · ' . $shift['start'] . '–' . $shift['end'] ) . '</option>';
			}
			echo '</optgroup>';
		}
		echo '</select></label></p>';
		self::input( 'children', __( 'Children', 'ludora-booking' ), (string) ( $r['children'] ?? 10 ), 'number' );
		self::input( 'adults', __( 'Adults', 'ludora-booking' ), (string) ( $r['adults'] ?? 2 ), 'number' );
		if ( ! empty( $r['package_id'] ) ) {
			echo '<input type="hidden" name="package_id" value="' . (int) $r['package_id'] . '"><p>' . esc_html__( 'This reservation keeps its package. The configured room, mode, duration and included extras still apply.', 'ludora-booking' ) . '</p>';
		}
		echo '<h3>' . esc_html__( 'Extras and quantities', 'ludora-booking' ) . '</h3><input type="hidden" name="ludora_extras_guided" value="1"><p>' . esc_html__( 'Use 0 to omit an optional extra. Required extras and room restrictions are checked when saving.', 'ludora-booking' ) . '</p>';
		foreach ( Catalog::extras() as $extra ) {
			$quantity = $r['extras'][ $extra['id'] ] ?? ( ! empty( $extra['required'] ) ? max( 1, (int) ( $extra['min'] ?? 0 ) ) : 0 );
			self::input( 'extra_' . $extra['id'], $extra['name'], (string) $quantity, 'number' );
		}
		echo '</fieldset>';
	}

	/**
	 * Return real published rooms for named administrative selectors.
	 *
	 * @return array
	 */
	private static function rooms(): array {
		return get_posts(
			array(
				'post_type'   => 'ludora_room',
				'numberposts' => -1,
				'post_status' => 'publish',
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
	}

	/**
	 * Render a named room selector.
	 *
	 * @param int  $selected Selected room ID.
	 * @param bool $all Include all rooms for list filters.
	 */
	private static function room_select( int $selected = 0, bool $all = false ): void {
		echo '<p><label>' . esc_html__( 'Room', 'ludora-booking' ) . '<br><select name="room_id"' . ( $all ? '' : ' required' ) . '>';
		echo '<option value="">' . esc_html( $all ? __( 'All rooms', 'ludora-booking' ) : __( 'Choose a room', 'ludora-booking' ) ) . '</option>';
		foreach ( self::rooms() as $room ) {
			echo '<option value="' . (int) $room->ID . '" ' . selected( $selected, $room->ID, false ) . '>' . esc_html( $room->post_title ) . '</option>';
		}
		echo '</select></label></p>';
	}

	/**
	 * Validate unslashed administrative reservation fields without truncation.
	 *
	 * @param array $input Unslashed submitted fields.
	 * @return array
	 * @throws \DomainException When numeric fields or extras are malformed.
	 */
	private static function request( array $input ): array {
		$r = Catalog::request( $input );
		foreach ( array( 'room_id', 'duration', 'children', 'adults', 'package_id' ) as $key ) {
			$value = $r[ $key ] ?? 0;
			if ( ! is_scalar( $value ) || ! preg_match( '/^\d{1,9}$/D', (string) $value ) ) {
				throw new \DomainException( 'An integer value is required.' );
			}
			$r[ $key ] = (int) $value;
		}
		foreach ( array( 'date', 'mode', 'start', 'shift' ) as $key ) {
			if ( isset( $r[ $key ] ) && ! is_string( $r[ $key ] ) ) {
				throw new \DomainException( 'Use a valid date and HH:MM local time.' );
			}
			$r[ $key ] = sanitize_text_field( $r[ $key ] ?? '' );
		}
		if ( ! empty( $input['ludora_extras_guided'] ) ) {
			$r['extras'] = array();
			foreach ( $input as $key => $value ) {
				if ( ! preg_match( '/^extra_(\d+)$/D', $key, $match ) ) {
					continue;
				}
				if ( ! is_scalar( $value ) || ! preg_match( '/^\d{1,6}$/D', (string) $value ) ) {
					throw new \DomainException( 'Choose an allowed extra quantity.' );
				}
				if ( (int) $value > 0 ) {
					$r['extras'][ (int) $match[1] ] = (int) $value;
				}
			}
		} else {
			$r['extras'] = is_string( $input['extras'] ?? null ) ? json_decode( $input['extras'], true ) : array();
			if ( ! is_array( $r['extras'] ) ) {
				throw new \DomainException( 'Extras must be quantities indexed by ID.' );
			}
		}
		return $r;
	}
	/**
	 * Open a nonce-protected administration action form.
	 *
	 * @param string $operation Administration operation.
	 * @param int    $id Catalog or reservation identifier.
	 */
	private static function action_form( string $operation, int $id = 0 ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ludora_action"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( 'ludora_action' ); }
	/**
	 * Render reservation details and available management actions.
	 *
	 * @param array $row Persisted reservation record.
	 */
	private static function detail( array $row ): void {
		$store    = new Store();
		$q        = json_decode( $row['snapshot'], true );
		$customer = json_decode( $row['customer'], true );
		echo '<h2>' . esc_html( $row['reference'] ) . '</h2><p>' . esc_html( Errors::state( $store->public_view( $row )['state'] ) . ' / ' . Errors::state( $row['payment_state'] ) . ' · ' . self::date( $row['start_utc'] ) . ' — ' . self::date( $row['end_utc'] ) ) . '</p><p>' . esc_html( ( $customer['name'] ?? '' ) . ' · ' . ( $customer['email'] ?? '' ) . ' · ' . ( $customer['phone'] ?? '' ) ) . '</p><pre>' . esc_html( wp_json_encode( $q, JSON_PRETTY_PRINT ) ) . '</pre>';
		$order = $row['order_id'] && function_exists( 'wc_get_order' ) ? wc_get_order( $row['order_id'] ) : null;
		if ( $order ) {
			echo '<p><a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html__( 'View WooCommerce order / manage refunds', 'ludora-booking' ) . '</a></p>'; }
		self::action_form( 'transition', (int) $row['id'] );
		echo '<label>' . esc_html__( 'New reservation state', 'ludora-booking' ) . ' <select name="state">';
		foreach ( array( 'confirmed', 'rejected', 'cancelled', 'completed' ) as $state ) {
			echo '<option value="' . esc_attr( $state ) . '">' . esc_html( Errors::state( $state ) ) . '</option>';
		} echo '</select></label>';
		self::input( 'note', __( 'Audit note', 'ludora-booking' ) );
		echo '<button class="button">' . esc_html__( 'Apply state', 'ludora-booking' ) . '</button></form>';
		echo '<h2>' . esc_html__( 'Reschedule safely', 'ludora-booking' ) . '</h2>';
		self::action_form( 'reschedule', (int) $row['id'] );
		self::request_fields( json_decode( $row['request'], true ) );
		echo '<button class="button">' . esc_html__( 'Validate and reschedule', 'ludora-booking' ) . '</button></form><h2>' . esc_html__( 'Audit log', 'ludora-booking' ) . '</h2><ul>';
		foreach ( $store->history( (int) $row['id'] ) as $event ) {
			echo '<li>' . esc_html( self::date( $event['created_at'] ) . ' · #' . $event['actor'] . ' · ' . $event['event'] . ' · ' . $event['detail'] ) . '</li>';
		} echo '</ul>';
	}
	/**
	 * Render the business settings form.
	 */
	public static function settings(): void {
		self::start( __( 'Business and reservation settings', 'ludora-booking' ) );
		$s = Settings::get();
		self::action_form( 'settings' );
		foreach ( array(
			'timezone'         => __( 'Business IANA timezone', 'ludora-booking' ),
			'currency'         => __( 'Currency ISO code (manual mode)', 'ludora-booking' ),
			'precision'        => __( 'Currency decimal precision (manual mode)', 'ludora-booking' ),
			'tax_basis_points' => __( 'Tax basis points added to net prices (manual mode)', 'ludora-booking' ),
			'hold_minutes'     => __( 'Checkout hold expiry in minutes', 'ludora-booking' ),
			'pending_hours'    => __( 'Manual pending request expiry in hours', 'ludora-booking' ),
			'retention_days'   => __( 'Personal data retention after reservation in days', 'ludora-booking' ),
			'terms_page'       => __( 'Published booking conditions page ID', 'ludora-booking' ),
			'business_email'   => __( 'Business notification email', 'ludora-booking' ),
			'phone'            => __( 'Business phone', 'ludora-booking' ),
			'address'          => __( 'Business address', 'ludora-booking' ),
			'hours'            => __( 'Opening hours for contact information', 'ludora-booking' ),
		) as $key => $label ) {
			self::input( $key, $label, (string) $s[ $key ] ); }
		echo '<p><label>' . esc_html__( 'Booking mode', 'ludora-booking' ) . ' <select name="mode"><option value="manual" ' . selected( $s['mode'], 'manual', false ) . '>' . esc_html__( 'Manual requests', 'ludora-booking' ) . '</option><option value="woocommerce" ' . selected( $s['mode'], 'woocommerce', false ) . '>WooCommerce</option></select></label></p><p>' . esc_html__( 'Without WooCommerce, configured tax is added once to net prices and shown separately. WooCommerce controls currency, precision and tax when payments are enabled. Requests never imply payment or confirmation.', 'ludora-booking' ) . '</p><label><input type="checkbox" name="purge" value="1" ' . checked( ! empty( $s['purge'] ), true, false ) . '>' . esc_html__( 'Permanently purge plugin tables and settings only when uninstalling', 'ludora-booking' ) . '</label><p><button class="button button-primary">' . esc_html__( 'Save settings', 'ludora-booking' ) . '</button></p></form></div>';
	}
	/**
	 * Authorize and dispatch reservation administration actions.
	 *
	 * @throws \DomainException When submitted settings or operations are invalid.
	 */
	public static function action(): void {
		self::permitted();
		check_admin_referer( 'ludora_action' );
		$operation = sanitize_key( $_POST['operation'] ?? '' );
		$store     = new Store();
		$id        = absint( $_POST['id'] ?? 0 );
		try {
			if ( 'settings' === $operation ) {
				$s = Settings::get();
				foreach ( array_keys( Settings::defaults() ) as $key ) {
					if ( isset( $_POST[ $key ] ) ) {
						$s[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) ); }
				}
				if ( ! in_array( $s['timezone'], timezone_identifiers_list(), true ) || ! preg_match( '/^[A-Z]{3}$/D', $s['currency'] ) || ! in_array( $s['mode'], array( 'manual', 'woocommerce' ), true ) ) {
					throw new \DomainException( 'Invalid timezone, currency or booking mode.' ); }
				foreach ( array(
					'precision'        => array( 0, 3 ),
					'hold_minutes'     => array( 1, 120 ),
					'pending_hours'    => array( 1, 720 ),
					'retention_days'   => array( 1, 3650 ),
					'tax_basis_points' => array( 0, 10000 ),
					'terms_page'       => array( 0, PHP_INT_MAX ),
				) as $key => $range ) {
					if ( ! is_numeric( $s[ $key ] ) || (int) $s[ $key ] < $range[0] || (int) $s[ $key ] > $range[1] ) {
						throw new \DomainException( 'A numeric setting is outside its allowed range.' );
					} $s[ $key ] = (int) $s[ $key ]; }
				$s['business_email'] = sanitize_email( $s['business_email'] );
				$s['purge']          = isset( $_POST['purge'] );
				$store->transaction( static fn() => update_option( 'ludora_settings', $s ) );
			} elseif ( 'create' === $operation || 'reschedule' === $operation ) {
				$r = self::request( wp_unslash( $_POST ) );
				if ( 'reschedule' === $operation ) {
					$store->reschedule( $id, $r ); } else {
					$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
					$name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
					if ( ! is_email( $email ) || ! $name ) {
						throw new \DomainException( 'Enter a valid responsible adult name and email.' );
					} $retry_key = hash( 'sha256', random_bytes( 32 ) );
					$token       = Store::access_token( $retry_key );
					$row         = $store->create(
						$r,
						array(
							'name'  => $name,
							'email' => $email,
							'phone' => '',
							'terms' => 'staff-recorded',
						),
						$retry_key,
						$token
					);
					$id          = (int) $row['id']; }
			} elseif ( 'transition' === $operation ) {
				$store->transition( $id, sanitize_key( $_POST['state'] ?? '' ), sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ) ); } elseif ( 'demo' === $operation ) {
				Demo::import( 'es' === sanitize_key( wp_unslash( $_POST['language'] ?? '' ) ) ? 'es' : 'en', isset( $_POST['set_home'] ) ); } elseif ( 'export' === $operation ) {
					self::export( $store );
					exit; } else {
					throw new \DomainException( 'Unknown operation.' ); }
		} catch ( \Throwable $e ) {
			set_transient( 'ludora_error_' . get_current_user_id(), Errors::message( $e->getMessage() ), 120 ); }
		wp_safe_redirect(
			add_query_arg(
				array_filter(
					array(
						'page'    => 'settings' === $operation ? 'ludora-settings' : ( 'demo' === $operation ? 'ludora-demo' : 'ludora' ),
						'booking' => $id,
					)
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
	/**
	 * Stream filtered reservations as formula-safe CSV.
	 *
	 * @param Store $store Transactional reservation store.
	 */
	private static function export( Store $store ): void {
		self::permitted();
		check_admin_referer( 'ludora_action' );
		$filters = array(
			'room_id' => absint( $_POST['room_id'] ?? 0 ),
			'state'   => sanitize_key( $_POST['state'] ?? '' ),
			'date'    => sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) ),
		);
		$store->listing( $filters, 1 );
		$rows = $store->export_rows( $filters );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=ludora-reservations.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Reference', 'Room', 'UTC start', 'UTC end', 'Booking state', 'Payment state', 'Name', 'Email', 'Total minor units', 'Currency' ), ',', '"', '' );
		foreach ( $rows as $row ) {
			$c      = json_decode( $row['customer'], true );
			$q      = json_decode( $row['snapshot'], true );
			$values = array( $row['reference'], get_the_title( (int) $row['room_id'] ), gmdate( 'c', $row['start_utc'] ), gmdate( 'c', $row['end_utc'] ), $row['state'], $row['payment_state'], $c['name'] ?? '', $c['email'] ?? '', $q['total'], $q['currency'] );
			$values = array_map( static fn( $value ) => preg_match( '/^[\s]*[=+@\-\t\r]/u', (string) $value ) ? "'" . $value : $value, $values );
			fputcsv( $out, $values, ',', '"', '' );
		}
		// Close the response stream; WP_Filesystem manages files, not HTTP output.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
	}
}
