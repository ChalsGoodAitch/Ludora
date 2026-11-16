<?php
/**
 * Optional WooCommerce checkout and payment adapter.
 *
 * @package LudoraBooking
 */

namespace Ludora\Booking;

defined( 'ABSPATH' ) || exit;

/** Optional full-payment adapter. Orders use WooCommerce CRUD and its order-pay endpoint. */
final class WooCommerce {
	/**
	 * Register optional WooCommerce hooks.
	 */
	public static function boot(): void {
		add_filter( 'woocommerce_get_item_data', array( self::class, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( self::class, 'item_meta' ), 10, 3 );
		add_action(
			'before_woocommerce_init',
			static function () {
				if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', LUDORA_BOOKING_FILE, true );
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', LUDORA_BOOKING_FILE, true );
				}
			}
		);
		add_action( 'woocommerce_before_calculate_totals', array( self::class, 'cart_prices' ) );
		add_action( 'woocommerce_check_cart_items', array( self::class, 'validate_cart' ) );
		add_filter( 'woocommerce_customer_taxable_address', array( self::class, 'tax_address' ) );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'order_meta' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( self::class, 'order_meta' ) );
		add_action( 'woocommerce_checkout_order_created', array( self::class, 'link_order' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'link_order' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( self::class, 'add_validation' ), 10, 6 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'sync' ) );
		add_action( 'woocommerce_order_status_changed', array( self::class, 'sync' ) );
		add_action( 'woocommerce_order_refunded', array( self::class, 'sync' ) );
	}
	/**
	 * Check whether the owner selected the installed payment integration.
	 */
	public static function enabled(): bool {
		return 'woocommerce' === ( get_option( 'ludora_settings', array() )['mode'] ?? 'manual' ) && function_exists( 'wc_create_order' ) && class_exists( '\WC_Tax' );
	}
	/**
	 * Use the active WooCommerce fiscal settings.
	 *
	 * @param array $settings Manual booking settings.
	 */
	public static function authority( array $settings ): array {
		if ( self::enabled() ) {
			$settings['currency']         = get_woocommerce_currency();
			$settings['precision']        = wc_get_price_decimals();
			$settings['tax_basis_points'] = 0;
		}
		return $settings;
	}
	/**
	 * Price the aggregate venue service with WooCommerce standard base tax.
	 *
	 * @param array $quote Net domain quote.
	 */
	public static function price_quote( array $quote ): array {
		if ( ! self::enabled() ) {
			return $quote;
		}
		$precision = wc_get_price_decimals();
		$factor    = 10 ** $precision;
		$net       = (int) ( $quote['subtotal'] ?? ( $quote['total'] - $quote['tax'] ) );
		$rates     = wc_tax_enabled() ? \WC_Tax::get_base_tax_rates( '' ) : array();
		$raw       = \WC_Tax::calc_tax( $net / $factor, $rates, false );
		$taxes     = array();
		foreach ( $raw as $id => $amount ) {
			$taxes[ $id ] = (int) round( wc_round_tax_total( $amount, $precision ) * $factor );
		}
		$quote['currency']  = get_woocommerce_currency();
		$quote['precision'] = $precision;
		$quote['subtotal']  = $net;
		$quote['tax']       = array_sum( $taxes );
		$quote['total']     = $net + $quote['tax'];
		$quote['wc_tax']    = array(
			'rates'             => $rates,
			'amounts'           => $taxes,
			'location'          => wc_get_base_location(),
			'round_at_subtotal' => get_option( 'woocommerce_tax_round_at_subtotal', 'no' ),
			'net_prices'        => true,
		);
		// Normalize WC's integral floats to the persisted JSON snapshot representation.
		$quote['wc_tax'] = json_decode( wp_json_encode( $quote['wc_tax'] ), true );
		return $quote;
	}
	/**
	 * Prepare one signed reservation in the native WooCommerce cart.
	 *
	 * @param array $booking Stored booking row.
	 * @throws \DomainException When the booking or checkout cannot be used.
	 */
	public static function checkout( array $booking ): string {
		if ( ! self::enabled() ) {
			throw new \DomainException( 'WooCommerce payments are unavailable.' );
		}
		if ( ! empty( $booking['order_id'] ) ) {
			return self::order_pay( $booking );
		}
		if ( ! WC()->cart ) {
			wc_load_cart();
		}
		WC()->session->set_customer_session_cookie( true );
		$product_id = ( new Store() )->transaction(
			static function () {
				$id      = (int) get_option( 'ludora_wc_product', 0 );
				$product = $id ? wc_get_product( $id ) : false;
				if ( ! $product ) {
					$product = new \WC_Product_Simple();
					$product->set_name( __( 'Venue reservation', 'ludora-booking' ) );
					$product->set_status( 'publish' );
					$product->set_catalog_visibility( 'hidden' );
					$product->set_virtual( true );
					$product->set_sold_individually( true );
					$product->set_regular_price( '0' );
					$product->set_tax_status( 'taxable' );
					$product->update_meta_data( '_ludora_carrier', 'yes' );
					$id = $product->save();
					update_option( 'ludora_wc_product', $id, false );
				}
				if ( 'publish' !== $product->get_status() ) {
					$product->set_status( 'publish' );
					$product->save();
				}
				return $id;
			}
		);
		$row        = ( new Store() )->get( (int) $booking['id'] );
		self::valid_booking( $row );
		WC()->cart->empty_cart();
		$data = array(
			'ludora_booking_id' => (int) $row['id'],
			'ludora_signature'  => self::cart_signature( $row ),
		);
		if ( ! WC()->cart->add_to_cart( $product_id, 1, 0, array(), $data ) ) {
			throw new \DomainException( 'Unable to prepare the reservation checkout.' );
		}
		WC()->cart->calculate_totals();
		WC()->cart->set_session();
		return wc_get_checkout_url();
	}
	/**
	 * Sign the server-owned cart snapshot.
	 *
	 * @param array $row Stored booking row.
	 */
	private static function cart_signature( array $row ): string {
		return hash_hmac( 'sha256', $row['id'] . ':' . $row['token_hash'] . ':' . $row['snapshot'], wp_salt( 'auth' ) );
	}
	/**
	 * Reject unavailable or expired payment holds.
	 *
	 * @param array|null $row Stored booking row.
	 * @throws \DomainException When the booking or checkout cannot be used.
	 */
	private static function valid_booking( ?array $row ): void {
		if ( ! $row || 'hold' !== $row['state'] || 'pending' !== $row['payment_state'] || (int) $row['expires_at'] <= time() ) {
			throw new \DomainException( 'The reservation hold expired or changed. Request availability again.' );
		}
	}
	/**
	 * Read and validate the single signed reservation from the cart.
	 *
	 * @throws \DomainException When the booking or checkout cannot be used.
	 */
	private static function cart_booking(): ?array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}
		$items    = WC()->cart->get_cart();
		$bookings = array_filter( $items, static fn( $item ) => ! empty( $item['ludora_booking_id'] ) );
		if ( ! $bookings ) {
			return null;
		}
		if ( count( $items ) !== 1 || count( $bookings ) !== 1 ) {
			throw new \DomainException( 'Checkout must contain exactly one reservation.' );
		}
		$item = reset( $bookings );
		$row  = ( new Store() )->get( (int) $item['ludora_booking_id'] );
		self::valid_booking( $row );
		if ( 1 !== (int) $item['quantity'] || ! hash_equals( self::cart_signature( $row ), (string) ( $item['ludora_signature'] ?? '' ) ) ) {
			throw new \DomainException( 'The reservation checkout changed. Start again.' );
		}
		return $row;
	}
	/**
	 * Reject direct or unsigned additions of the private carrier product.
	 *
	 * @param bool  $valid Existing validation result.
	 * @param int   $product_id Product identifier.
	 * @param int   $quantity Requested quantity.
	 * @param int   $variation_id Variation identifier.
	 * @param array $variations Selected variations.
	 * @param array $data Additional server cart data.
	 */
	public static function add_validation( $valid, $product_id, $quantity, $variation_id = 0, $variations = array(), $data = array() ): bool {
		if ( get_post_meta( $product_id, '_ludora_carrier', true ) === 'yes' ) {
			$row = ( new Store() )->get( (int) ( $data['ludora_booking_id'] ?? 0 ) );
			return $valid && $row && 1 === (int) $quantity && hash_equals( self::cart_signature( $row ), (string) ( $data['ludora_signature'] ?? '' ) );
		}
		return $valid;
	}
	/**
	 * Apply server snapshot prices before native cart calculation.
	 *
	 * @param \WC_Cart $cart Current WooCommerce cart.
	 * @throws \DomainException Caught locally and converted into a checkout notice.
	 */
	public static function cart_prices( $cart ): void {
		try {
			$row = self::cart_booking();
			if ( ! $row ) {
				return;
			}
			$q = json_decode( $row['snapshot'], true );
			if ( get_woocommerce_currency() !== $q['currency'] || wc_get_price_decimals() !== (int) $q['precision'] ) {
				throw new \DomainException( 'The payment currency changed. Request a new reservation quote.' );
			}
			$amount = wc_prices_include_tax() ? $q['total'] : $q['subtotal'];
			foreach ( $cart->get_cart() as $item ) {
				$item['data']->set_price( $amount / ( 10 ** (int) $q['precision'] ) );
				$item['data']->set_tax_class( '' );
			}
		} catch ( \DomainException $e ) {
			wc_add_notice( Errors::message( $e->getMessage() ), 'error' );
		}
	}
	/**
	 * Expose domain validation failures as checkout notices.
	 */
	public static function validate_cart(): void {
		try {
			self::cart_booking();
		} catch ( \DomainException $e ) {
			wc_add_notice( Errors::message( $e->getMessage() ), 'error' );
		}
	}
	/**
	 * Tax the physical venue service at the business location.
	 *
	 * @param array $address Existing customer taxable address.
	 */
	public static function tax_address( array $address ): array {
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				if ( ! empty( $item['ludora_booking_id'] ) ) {
					$base = wc_get_base_location();
					return array( $base['country'], $base['state'], get_option( 'woocommerce_store_postcode', '' ), get_option( 'woocommerce_store_city', '' ) );
				}
			}
		}
		return $address;
	}
	/**
	 * Attach booking identity to native classic or Blocks orders.
	 *
	 * @param \WC_Order $order Native checkout order.
	 */
	public static function order_meta( $order ): void {
		$row = self::cart_booking();
		if ( $row ) {
			$order->update_meta_data( '_ludora_booking_id', (int) $row['id'] );
			$order->update_meta_data( '_ludora_snapshot_signature', self::fiscal_signature( json_decode( $row['snapshot'], true ) ) );
			$order->update_meta_data( '_ludora_tax_location', 'venue' );
			$order->update_meta_data( '_ludora_reference', $row['reference'] );
			$order->update_meta_data( '_ludora_snapshot', json_decode( $row['snapshot'], true ) );
		}
	}
	/**
	 * Describe signed booking items in both classic and Store API checkout.
	 *
	 * @param array $data Existing visible cart details.
	 * @param array $values Signed reservation cart values.
	 * @return array
	 */
	public static function item_data( array $data, array $values ): array {
		$row = ! empty( $values['ludora_booking_id'] ) ? ( new Store() )->get( (int) $values['ludora_booking_id'] ) : null;
		if ( ! $row || ! hash_equals( self::cart_signature( $row ), (string) ( $values['ludora_signature'] ?? '' ) ) ) {
			return $data;
		}
		$q       = json_decode( $row['snapshot'], true );
		$zone    = new \DateTimeZone( $q['timezone'] );
		$details = array(
			__( 'Reference', 'ludora-booking' ) => $row['reference'],
			__( 'Room', 'ludora-booking' )      => $q['lines'][0]['name'],
			__( 'Date', 'ludora-booking' )      => wp_date( 'Y-m-d', (int) $row['start_utc'], $zone ),
			__( 'Time', 'ludora-booking' )      => wp_date( 'H:i', (int) $row['start_utc'], $zone ) . '–' . wp_date( 'H:i T', (int) $row['end_utc'], $zone ),
		);
		$extras  = array_column( array_slice( $q['lines'], 1 ), 'name' );
		if ( $extras ) {
			$details[ __( 'Extras', 'ludora-booking' ) ] = implode( ', ', $extras ); }
		foreach ( $details as $key => $value ) {
			$data[] = array(
				'key'   => $key,
				'value' => sanitize_text_field( $value ),
			); }
		return $data;
	}
	/**
	 * Preserve customer-readable scheduling details on the official order line.
	 *
	 * @param \WC_Order_Item_Product $item Native order item.
	 * @param string                 $cart_item_key Native cart key.
	 * @param array                  $values Signed reservation cart values.
	 */
	public static function item_meta( $item, string $cart_item_key, array $values ): void {
		foreach ( self::item_data( array(), $values ) as $detail ) {
			$item->add_meta_data( $detail['key'], $detail['value'], true ); }
	}
	/**
	 * Link a validated native order under the domain transaction lock.
	 *
	 * @param \WC_Order $order Native checkout order.
	 */
	public static function link_order( $order ): void {
		$id = (int) $order->get_meta( '_ludora_booking_id' );
		if ( ! $id ) {
			return;
		}
		( new Store() )->transaction(
			static function () use ( $order, $id ) {
				global $wpdb;
				$store = new Store();
				$row   = $store->get( $id );
				self::valid_booking( $row );
				$q = json_decode( $row['snapshot'], true );
				if ( ( ! empty( $row['order_id'] ) && (int) $row['order_id'] !== $order->get_id() ) || $order->get_currency() !== $q['currency'] || (int) round( (float) $order->get_total() * ( 10 ** (int) $q['precision'] ) ) !== (int) $q['total'] || (int) round( (float) $order->get_total_tax() * ( 10 ** (int) $q['precision'] ) ) !== (int) $q['tax'] || $order->get_fees() || $order->get_coupon_codes() || count( $order->get_items() ) !== 1 || self::price_quote( $q )['wc_tax'] !== $q['wc_tax'] ) {
					throw new \DomainException( 'The payment total or linked order changed. Request a new quote.' );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic linkage uses the domain's live InnoDB transaction, never cached rows.
				if ( false === $wpdb->update( $wpdb->prefix . 'ludora_bookings', array( 'order_id' => $order->get_id() ), array( 'id' => $id ) ) ) {
					throw new \RuntimeException( 'Unable to link reservation order.' );
				}
				if ( empty( $row['order_id'] ) ) {
					$store->event( $id, 'order_linked', (string) $order->get_id() );
				}
			}
		);
	}
	/**
	 * Reuse the official payment link for an existing linked order.
	 *
	 * @param array $booking Stored booking row.
	 * @throws \DomainException When the booking or checkout cannot be used.
	 */
	private static function order_pay( array $booking ): string {
		$row   = ( new Store() )->get( (int) $booking['id'] );
		$order = $row && $row['order_id'] ? wc_get_order( (int) $row['order_id'] ) : false;
		if ( ! $order || (int) $order->get_meta( '_ludora_booking_id' ) !== (int) $row['id'] ) {
			throw new \DomainException( 'The linked payment order is unavailable. Contact the venue.' );
		}
		return $order->is_paid() ? $order->get_checkout_order_received_url() : $order->get_checkout_payment_url();
	}
	/**
	 * Sign fiscal values independently of reservation scheduling.
	 *
	 * @param array $quote Persisted quote.
	 */
	private static function fiscal_signature( array $quote ): string {
		return hash_hmac( 'sha256', wp_json_encode( array_intersect_key( $quote, array_flip( array( 'currency', 'precision', 'subtotal', 'tax', 'total', 'lines', 'wc_tax' ) ) ) ), wp_salt( 'auth' ) );
	}
	/**
	 * Forward verified WooCommerce events to the domain payment service.
	 *
	 * @param int $order_id WooCommerce order identifier.
	 */
	public static function sync( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_ludora_booking_id' ) ) {
			return;
		}
		$store = new Store();
		$id    = (int) $order->get_meta( '_ludora_booking_id' );
		$row   = $store->get( $id );
		if ( ! $row || (int) $row['order_id'] !== $order->get_id() ) {
			return;
		}
		$q = json_decode( $row['snapshot'], true );
		if ( ! hash_equals( self::fiscal_signature( $q ), (string) $order->get_meta( '_ludora_snapshot_signature' ) ) || $q['currency'] !== $order->get_currency() || (int) round( (float) $order->get_total_tax() * ( 10 ** (int) $q['precision'] ) ) !== (int) $q['tax'] || (int) round( (float) $order->get_total() * ( 10 ** (int) $q['precision'] ) ) !== (int) $q['total'] ) {
			$order->add_order_note( __( 'Ludora payment needs venue review: the fiscal snapshot does not match.', 'ludora-booking' ) );
			return;
		}
		$state = null;
		if ( $order->has_status( 'refunded' ) && (float) $order->get_remaining_refund_amount() <= 0 ) {
			$state = 'refunded';
		} elseif ( $order->is_paid() ) {
			$state = 'paid';
		} elseif ( $order->has_status( array( 'failed', 'cancelled' ) ) ) {
			$state = 'failed';
		}
		if ( $state ) {
			$store->payment( $id, $state, $order->get_id() );
		}
	}
}
