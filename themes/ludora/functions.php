<?php
/** Theme presentation and optional companion recommendations.
 *
 * @package Ludora
 */

defined( 'ABSPATH' ) || exit;

/** Register theme features. */
function ludora_setup() {
	load_theme_textdomain( 'ludora', get_template_directory() . '/languages' );
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'editor-styles' );
	add_editor_style( 'assets/css/theme.css' );
}
add_action( 'after_setup_theme', 'ludora_setup' );

/** Load the same presentation on the public site. */
function ludora_styles() {
	wp_enqueue_style( 'ludora-theme', get_template_directory_uri() . '/assets/css/theme.css', array(), wp_get_theme( get_template() )->get( 'Version' ) );
}
add_action( 'wp_enqueue_scripts', 'ludora_styles' );

/** Register native pattern categories. */
function ludora_patterns() {
	register_block_pattern_category( 'ludora', array( 'label' => __( 'Ludora homepages', 'ludora' ) ) );
	register_block_style(
		'core/group',
		array(
			'name'         => 'ludora-feature',
			'label'        => __( 'Rounded feature card', 'ludora' ),
			'inline_style' => '.is-style-ludora-feature{padding:2rem;border:1px solid #25253622;border-radius:1.5rem;background:#fff}',
		)
	);
}
add_action( 'init', 'ludora_patterns' );

require_once get_template_directory() . '/inc/class-tgm-plugin-activation.php';
/** Offer optional plugins without installing or activating them. */
function ludora_companions() {
	tgmpa(
		array(
			array(
				'name'     => 'Ludora Booking',
				'slug'     => 'ludora-booking',
				'source'   => get_template_directory() . '/plugins/ludora-booking.zip',
				'required' => false,
			),
			array(
				'name'     => 'WooCommerce',
				'slug'     => 'woocommerce',
				'required' => false,
			),
		),
		array(
			'id'           => 'ludora',
			'menu'         => 'ludora-companions',
			'has_notices'  => true,
			'dismissable'  => true,
			'is_automatic' => false,
		)
	);
}
add_action( 'tgmpa_register', 'ludora_companions' );

/**
 * Keep the native page-list fallback valid inside a Navigation list.
 *
 * WordPress 7.1 wraps any child containing LI elements in a UL, while Page List
 * also supplies its own UL. Remove only that outer wrapper in nav context.
 *
 * @param string   $content Rendered page list.
 * @param array    $block Parsed block.
 * @param WP_Block $instance Rendered block instance.
 * @return string
 */
function ludora_navigation_page_list( $content, $block, $instance ) {
	if ( array_key_exists( 'showSubmenuIcon', $instance->context ) && version_compare( get_bloginfo( 'version' ), '7.1', '>=' ) ) {
		$content = preg_replace( '/^<ul\\b[^>]*>(.*)<\\/ul>$/s', '$1', trim( $content ) );
	}
	return $content;
}
add_filter( 'render_block_core/page-list', 'ludora_navigation_page_list', 10, 3 );
