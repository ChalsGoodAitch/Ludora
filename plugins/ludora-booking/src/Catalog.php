<?php
/**
 * Expose catalog content as booking configuration.
 *
 * @package Ludora\Booking
 */

namespace Ludora\Booking;

defined( 'ABSPATH' ) || exit;

/** Expose catalog content as booking configuration. */
final class Catalog {
	/**
	 * Register the room, extra and package content types.
	 */
	public static function register(): void {
		foreach ( array(
			'ludora_room'    => array( __( 'Rooms', 'ludora-booking' ), 'rooms' ),
			'ludora_extra'   => array( __( 'Extras', 'ludora-booking' ), 'services' ),
			'ludora_package' => array( __( 'Packages', 'ludora-booking' ), 'birthday-packages' ),
		) as $type => $data ) {
			register_post_type(
				$type,
				array(
					'labels'          => array(
						'name'          => $data[0],
						'singular_name' => $data[0],
					),
					'public'          => true,
					'show_in_rest'    => true,
					'has_archive'     => 'ludora_room' === $type ? 'room-archive' : true,
					'rewrite'         => array( 'slug' => $data[1] ),
					'show_in_menu'    => 'ludora',
					'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ),
					'map_meta_cap'    => false,
					'capability_type' => 'post',
					'capabilities'    => array(
						'read_post'              => 'manage_ludora',
						'edit_post'              => 'manage_ludora',
						'delete_post'            => 'manage_ludora',
						'edit_posts'             => 'manage_ludora',
						'edit_others_posts'      => 'manage_ludora',
						'edit_private_posts'     => 'manage_ludora',
						'edit_published_posts'   => 'manage_ludora',
						'publish_posts'          => 'manage_ludora',
						'create_posts'           => 'manage_ludora',
						'delete_posts'           => 'manage_ludora',
						'delete_private_posts'   => 'manage_ludora',
						'delete_published_posts' => 'manage_ludora',
						'delete_others_posts'    => 'manage_ludora',
						'read_private_posts'     => 'manage_ludora',
					),
				)
			);
		}
	}
	/**
	 * Read configuration stored for a catalog item.
	 *
	 * @param int $id Catalog or reservation identifier.
	 * @return array Resolved values.
	 */
	public static function config( int $id ): array {
		$value = get_post_meta( $id, '_ludora_config', true );
		return is_array( $value ) ? $value : array();
	}
	/**
	 * Resolve a published room and authoritative business settings.
	 *
	 * @throws \DomainException When the room is unavailable.
	 *
	 * @param int $id Catalog or reservation identifier.
	 * @return array Resolved values.
	 */
	public static function room( int $id ): array {
		$post = get_post( $id );
		if ( ! $post || 'ludora_room' !== $post->post_type || 'publish' !== $post->post_status ) {
			throw new \DomainException( 'Room is not available.' ); }
		$settings                 = WooCommerce::authority( Settings::get() );
		$room                     = self::config( $id );
		$room['name']             = $post->post_title;
		$room['id']               = $id;
		$room['timezone']         = $settings['timezone'];
		$room['currency']         = $settings['currency'];
		$room['precision']        = (int) $settings['precision'];
		$room['tax_basis_points'] = (int) $settings['tax_basis_points'];
		return $room;
	}
	/**
	 * Return published extras with their booking configuration.
	 *
	 * @return array Resolved values.
	 */
	public static function extras(): array {
		$result = array();
		foreach ( get_posts(
			array(
				'post_type'   => 'ludora_extra',
				'numberposts' => 100,
				'post_status' => 'publish',
			)
		) as $post ) {
			$extra         = self::config( $post->ID );
			$extra['id']   = $post->ID;
			$extra['name'] = $post->post_title;
			$result[]      = $extra;
		}
		return $result;
	}
	/**
	 * Keep only recognized reservation request fields.
	 *
	 * @param array $input Untrusted reservation request fields.
	 * @return array Resolved values.
	 */
	public static function request( array $input ): array {
		$allowed = array( 'room_id', 'date', 'mode', 'start', 'duration', 'shift', 'children', 'adults', 'extras', 'package_id' );
		return array_intersect_key( $input, array_flip( $allowed ) );
	}
}
