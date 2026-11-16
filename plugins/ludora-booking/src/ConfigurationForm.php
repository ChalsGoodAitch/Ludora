<?php
/**
 * Guided catalog configuration fields.
 *
 * @package LudoraBooking
 */

namespace Ludora\Booking;

defined( 'ABSPATH' ) || exit;

/** Presentation and validation for catalog editor fields. */
final class ConfigurationForm {
	/**
	 * Render guided fields and retain advanced configuration.
	 *
	 * @param \WP_Post $post Edited catalog item.
	 * @param array    $config Existing configuration.
	 */
	public static function render( $post, array $config ): void {
		echo '<div class="ludora-configuration"><p>' . esc_html__( 'Prices use integer minor units: 2500 means 25.00 in a two-decimal currency. Times use your business timezone. These rules affect new quotes.', 'ludora-booking' ) . '</p><input type="hidden" name="ludora_guided" value="1">';
		if ( 'ludora_room' === $post->post_type ) {
			self::check( 'active', __( 'Available for booking', 'ludora-booking' ), ! empty( $config['active'] ) );
			foreach ( self::room_numbers() as $key => $label ) {
				self::field( $key, $label, $config[ $key ] ?? 0, 'number' );
			}
			echo '<h3>' . esc_html__( 'Weekly opening hours', 'ludora-booking' ) . '</h3><p>' . esc_html__( 'Use HH:MM-HH:MM. Separate multiple intervals with commas. Leave a day empty to close it.', 'ludora-booking' ) . '</p>';
			$days = array(
				1 => __( 'Monday', 'ludora-booking' ),
				__( 'Tuesday', 'ludora-booking' ),
				__( 'Wednesday', 'ludora-booking' ),
				__( 'Thursday', 'ludora-booking' ),
				__( 'Friday', 'ludora-booking' ),
				__( 'Saturday', 'ludora-booking' ),
				__( 'Sunday', 'ludora-booking' ),
			);
			foreach ( $days as $day => $label ) {
				$intervals = array_map( static fn( $pair ) => implode( '-', $pair ), $config['weekly'][ $day ] ?? array() );
				self::field( 'weekly_' . $day, $label, implode( ', ', $intervals ) );
			}
			echo '<h3>' . esc_html__( 'Rental prices', 'ludora-booking' ) . '</h3>';
			foreach ( self::modes() as $key => $label ) {
				self::field( 'rate_' . $key, $label, $config['rates'][ $key ] ?? 0, 'number' );
			}
			$shifts = array();
			foreach ( $config['shifts'] ?? array() as $key => $shift ) {
				$shifts[] = implode( '|', array( $key, $shift['name'] ?? $key, $shift['start'], $shift['end'] ) );
			}
			self::field( 'shifts', __( 'Shifts: one per line as key|Name|HH:MM|HH:MM', 'ludora-booking' ), implode( "\n", $shifts ), 'textarea' );
			self::field( 'closed_dates', __( 'Closed dates: YYYY-MM-DD, separated by commas', 'ludora-booking' ), implode( ', ', $config['closed_dates'] ?? array() ), 'textarea' );
			self::field( 'equipment', __( 'Equipment and facilities', 'ludora-booking' ), $config['equipment'] ?? '', 'textarea' );
			self::field( 'rules', __( 'Room rules', 'ludora-booking' ), $config['rules'] ?? '', 'textarea' );
		} elseif ( 'ludora_extra' === $post->post_type ) {
			self::select(
				'basis',
				__( 'Price basis', 'ludora-booking' ),
				$config['basis'] ?? 'fixed',
				array(
					'fixed'    => __( 'Per reservation', 'ludora-booking' ),
					'hour'     => __( 'Per hour', 'ludora-booking' ),
					'attendee' => __( 'Per attendee', 'ludora-booking' ),
					'quantity' => __( 'Per item', 'ludora-booking' ),
				)
			);
			self::select(
				'attendee_basis',
				__( 'Attendees charged', 'ludora-booking' ),
				$config['attendee_basis'] ?? 'children',
				array(
					'children' => __( 'Children', 'ludora-booking' ),
					'adults'   => __( 'Adults', 'ludora-booking' ),
					'all'      => __( 'Everyone', 'ludora-booking' ),
				)
			);
			foreach ( array(
				'price'    => __( 'Price in minor units', 'ludora-booking' ),
				'min'      => __( 'Minimum quantity', 'ludora-booking' ),
				'max'      => __( 'Maximum quantity', 'ludora-booking' ),
				'capacity' => __( 'Shared capacity (0 means unlimited)', 'ludora-booking' ),
			) as $key => $label ) {
				self::field( $key, $label, $config[ $key ] ?? 0, 'number' );
			}
			self::check( 'required', __( 'Required extra', 'ludora-booking' ), ! empty( $config['required'] ) );
			self::field( 'rooms', __( 'Allowed room IDs, comma separated (empty means all)', 'ludora-booking' ), implode( ', ', $config['rooms'] ?? array() ) );
			foreach ( self::modes() as $key => $label ) {
				self::check( 'mode_' . $key, $label, in_array( $key, $config['modes'] ?? array(), true ) );
			}
		} else {
			$rooms = array( 0 => __( 'Choose a room', 'ludora-booking' ) );
			foreach ( get_posts(
				array(
					'post_type'   => 'ludora_room',
					'numberposts' => 100,
					'post_status' => array( 'publish', 'draft' ),
				)
			) as $room ) {
				$rooms[ $room->ID ] = $room->post_title;
			}
			self::select( 'room_id', __( 'Package room', 'ludora-booking' ), $config['room_id'] ?? 0, $rooms );
			self::select( 'mode', __( 'Rental mode', 'ludora-booking' ), $config['mode'] ?? 'shift', self::modes() );
			self::field( 'shift', __( 'Shift key (for shift rentals)', 'ludora-booking' ), $config['shift'] ?? '' );
			self::field( 'duration', __( 'Duration in minutes (for hourly rentals)', 'ludora-booking' ), $config['duration'] ?? 120, 'number' );
			echo '<h3>' . esc_html__( 'Included extras', 'ludora-booking' ) . '</h3>';
			foreach ( get_posts(
				array(
					'post_type'   => 'ludora_extra',
					'numberposts' => 100,
					'post_status' => 'publish',
				)
			) as $extra ) {
				self::field( 'extra_' . $extra->ID, $extra->post_title, $config['extras'][ $extra->ID ] ?? 0, 'number' );
			}
		}
		echo '<details style="margin-top:24px"><summary>' . esc_html__( 'Advanced configuration', 'ludora-booking' ) . '</summary><p>' . esc_html__( 'Opening exceptions, date rates and technical blocks are retained when saving guided fields. Enable advanced editing to replace the entire configuration with JSON.', 'ludora-booking' ) . '</p><p><label><input type="checkbox" name="ludora_advanced" value="1"> ' . esc_html__( 'Use advanced JSON instead of guided fields', 'ludora-booking' ) . '</label></p><textarea id="ludora-config-json" class="large-text code" rows="16" name="ludora_config">' . esc_textarea( wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</textarea></details></div>';
	}

	/**
	 * Translate unslashed form input into the configuration format.
	 *
	 * @param array  $input Unslashed posted fields.
	 * @param string $type Catalog post type.
	 * @throws \DomainException When configuration is invalid.
	 */
	public static function normalize( array $input, string $type ): array {
		$raw = $input['ludora_config'] ?? '{}';
		if ( ! is_string( $raw ) || strlen( $raw ) > 50000 ) {
			throw new \DomainException( esc_html__( 'Configuration is too large.', 'ludora-booking' ) );
		}
		$config = json_decode( $raw, true );
		if ( ! is_array( $config ) ) {
			throw new \DomainException( esc_html__( 'Enter a valid JSON configuration object.', 'ludora-booking' ) );
		}
		if ( ! empty( $input['ludora_advanced'] ) || empty( $input['ludora_guided'] ) ) {
			return $config;
		}
		$f = $input['ludora_fields'] ?? array();
		if ( ! is_array( $f ) ) {
			throw new \DomainException( esc_html__( 'Invalid guided configuration.', 'ludora-booking' ) );
		}
		if ( 'ludora_room' === $type ) {
			$config['active'] = ! empty( $f['active'] );
			foreach ( array_keys( self::room_numbers() ) as $key ) {
				$config[ $key ] = self::integer( $f[ $key ] ?? 0 );
			}
			if ( $config['increment'] < 1 || $config['min_duration'] < 1 || $config['max_duration'] < $config['min_duration'] ) {
				throw new \DomainException( esc_html__( 'Duration and increment must be positive; maximum duration must cover the minimum.', 'ludora-booking' ) );
			}
			foreach ( array(
				'increment'      => 1440,
				'buffer_before'  => 1440,
				'buffer_after'   => 1440,
				'notice_minutes' => 525600,
				'horizon_days'   => 36500,
			) as $key => $limit ) {
				if ( $config[ $key ] > $limit ) {
					throw new \DomainException( esc_html__( 'A timing rule exceeds the supported range.', 'ludora-booking' ) );
				}
			}
			$config['weekly'] = array();
			for ( $day = 1; $day <= 7; $day++ ) {
				$config['weekly'][ $day ] = array();
				foreach ( self::pieces( $f[ 'weekly_' . $day ] ?? '' ) as $interval ) {
					$pair = explode( '-', $interval );
					if ( count( $pair ) !== 2 ) {
						throw new \DomainException( esc_html__( 'Opening intervals must use HH:MM-HH:MM.', 'ludora-booking' ) );
					}
					self::times( $pair[0], $pair[1] );
					$config['weekly'][ $day ][] = $pair;
				}
			}
			foreach ( array_keys( self::modes() ) as $key ) {
				$config['rates'][ $key ] = self::integer( $f[ 'rate_' . $key ] ?? 0 );
			}
			$config['shifts'] = array();
			foreach ( preg_split( '/\R/', trim( $f['shifts'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) as $line ) {
				$parts = array_map( 'trim', explode( '|', $line ) );
				if ( count( $parts ) !== 4 || ! preg_match( '/^[a-z0-9_-]+$/D', $parts[0] ) || ! $parts[1] ) {
					throw new \DomainException( esc_html__( 'Each shift needs key|Name|HH:MM|HH:MM.', 'ludora-booking' ) );
				}
				self::times( $parts[2], $parts[3] );
				$config['shifts'][ $parts[0] ] = array(
					'name'  => sanitize_text_field( $parts[1] ),
					'start' => $parts[2],
					'end'   => $parts[3],
				);
			}
			$config['closed_dates'] = self::pieces( $f['closed_dates'] ?? '' );
			foreach ( $config['closed_dates'] as $date ) {
				$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
				if ( ! $d || $d->format( 'Y-m-d' ) !== $date ) {
					throw new \DomainException( esc_html__( 'Closed dates must be valid YYYY-MM-DD dates.', 'ludora-booking' ) );
				}
			}
			foreach ( array( 'equipment', 'rules' ) as $key ) {
				$config[ $key ] = sanitize_textarea_field( $f[ $key ] ?? '' );
			}
		} elseif ( 'ludora_extra' === $type ) {
			$config['basis']          = self::choice( $f['basis'] ?? '', array( 'fixed', 'hour', 'attendee', 'quantity' ) );
			$config['attendee_basis'] = self::choice( $f['attendee_basis'] ?? '', array( 'children', 'adults', 'all' ) );
			foreach ( array( 'price', 'min', 'max', 'capacity' ) as $key ) {
				$config[ $key ] = self::integer( $f[ $key ] ?? 0 );
			}
			if ( $config['max'] < $config['min'] ) {
				throw new \DomainException( esc_html__( 'Maximum quantity must cover the minimum.', 'ludora-booking' ) );
			}
			$config['required'] = ! empty( $f['required'] );
			$config['rooms']    = array_map( array( self::class, 'integer' ), self::pieces( $f['rooms'] ?? '' ) );
			$config['modes']    = array_values( array_filter( array_keys( self::modes() ), static fn( $key ) => ! empty( $f[ 'mode_' . $key ] ) ) );
		} elseif ( 'ludora_package' === $type ) {
			$config['room_id'] = self::integer( $f['room_id'] ?? 0 );
			if ( 'ludora_room' !== get_post_type( $config['room_id'] ) ) {
				throw new \DomainException( esc_html__( 'Choose a valid package room.', 'ludora-booking' ) );
			}
			$config['mode']     = self::choice( $f['mode'] ?? '', array_keys( self::modes() ) );
			$config['shift']    = sanitize_key( $f['shift'] ?? '' );
			$config['duration'] = self::integer( $f['duration'] ?? 120 );
			$config['extras']   = array();
			foreach ( $f as $key => $value ) {
				if ( str_starts_with( $key, 'extra_' ) && self::integer( $value ) > 0 ) {
					$id = (int) substr( $key, 6 );
					if ( 'ludora_extra' !== get_post_type( $id ) ) {
						throw new \DomainException( esc_html__( 'Choose valid package extras.', 'ludora-booking' ) );
					}
					$config['extras'][ $id ] = self::integer( $value );
				}
			}
		}
		return $config;
	}

	/**
	 * Validate an integer without silently truncating it.
	 *
	 * @param mixed $value Submitted integer.
	 * @throws \DomainException When the value is invalid.
	 */
	private static function integer( $value ): int {
		if ( ! is_scalar( $value ) || ! preg_match( '/^\d{1,9}$/D', (string) $value ) || (int) $value > 100000000 ) {
			throw new \DomainException( esc_html__( 'Use non-negative whole numbers within 100000000.', 'ludora-booking' ) );
		}
		return (int) $value;
	}

	/**
	 * Validate a choice.
	 *
	 * @param mixed $value Submitted choice.
	 * @param array $choices Allowed choices.
	 * @throws \DomainException When the choice is invalid.
	 */
	private static function choice( $value, array $choices ): string {
		if ( ! in_array( $value, $choices, true ) ) {
			throw new \DomainException( esc_html__( 'Choose a valid configuration option.', 'ludora-booking' ) );
		}
		return $value;
	}

	/**
	 * Parse comma separated values.
	 *
	 * @param string $value Submitted list.
	 */
	private static function pieces( string $value ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), static fn( $item ) => '' !== $item ) );
	}

	/**
	 * Validate local opening endpoints.
	 *
	 * @param string $start Opening time.
	 * @param string $end Closing time.
	 * @throws \DomainException When times are invalid.
	 */
	private static function times( string $start, string $end ): void {
		if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $start ) || ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $end ) || $start >= $end ) {
			throw new \DomainException( esc_html__( 'Times must use HH:MM with the end after the start on the same day.', 'ludora-booking' ) );
		}
	}

	/** Available rental modes. */
	private static function modes(): array {
		return array(
			'hourly' => __( 'Hourly rental', 'ludora-booking' ),
			'shift'  => __( 'Shift rental', 'ludora-booking' ),
			'day'    => __( 'Full opening day', 'ludora-booking' ),
		);
	}

	/** Numeric room labels. */
	private static function room_numbers(): array {
		return array(
			'capacity_children' => __( 'Child capacity', 'ludora-booking' ),
			'capacity_adults'   => __( 'Adult capacity', 'ludora-booking' ),
			'min_duration'      => __( 'Minimum duration (minutes)', 'ludora-booking' ),
			'max_duration'      => __( 'Maximum duration (minutes)', 'ludora-booking' ),
			'increment'         => __( 'Booking increment (minutes)', 'ludora-booking' ),
			'notice_minutes'    => __( 'Advance notice (minutes)', 'ludora-booking' ),
			'horizon_days'      => __( 'Booking horizon (days)', 'ludora-booking' ),
			'buffer_before'     => __( 'Buffer before (minutes)', 'ludora-booking' ),
			'buffer_after'      => __( 'Buffer after (minutes)', 'ludora-booking' ),
		);
	}

	/**
	 * Render a labelled field.
	 *
	 * @param string $key Field key.
	 * @param string $label Field label.
	 * @param mixed  $value Field value.
	 * @param string $type Input type.
	 */
	private static function field( string $key, string $label, $value, string $type = 'text' ): void {
		$id = 'ludora-field-' . $key;
		echo '<p><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>';
		if ( 'textarea' === $type ) {
			echo '<textarea class="large-text" rows="3" id="' . esc_attr( $id ) . '" name="ludora_fields[' . esc_attr( $key ) . ']">' . esc_textarea( $value ) . '</textarea>';
		} else {
			echo '<input class="regular-text" id="' . esc_attr( $id ) . '" name="ludora_fields[' . esc_attr( $key ) . ']" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '"' . ( 'number' === $type ? ' min="0" max="100000000" step="1"' : '' ) . '>';
		}
		echo '</p>';
	}

	/**
	 * Render a labelled checkbox.
	 *
	 * @param string $key Field key.
	 * @param string $label Field label.
	 * @param bool   $value Checked value.
	 */
	private static function check( string $key, string $label, bool $value ): void {
		echo '<p><label><input type="checkbox" name="ludora_fields[' . esc_attr( $key ) . ']" value="1" ' . checked( $value, true, false ) . '> ' . esc_html( $label ) . '</label></p>';
	}

	/**
	 * Render a labelled select.
	 *
	 * @param string $key Field key.
	 * @param string $label Field label.
	 * @param mixed  $value Selected value.
	 * @param array  $options Available choices.
	 */
	private static function select( string $key, string $label, $value, array $options ): void {
		echo '<p><label><strong>' . esc_html( $label ) . '</strong><br><select name="ludora_fields[' . esc_attr( $key ) . ']">';
		foreach ( $options as $option => $text ) {
			echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></label></p>';
	}
}
