<?php
/**
 * Example safe stylesheet customization.
 *
 * @package LudoraChild
 */

defined( 'ABSPATH' ) || exit;

/** Load the child's original metadata translations from its own directory. */
function ludora_child_setup() {
	load_child_theme_textdomain( 'ludora-child', get_stylesheet_directory() . '/languages' );
}
add_action( 'after_setup_theme', 'ludora_child_setup' );

/** Load parent presentation and then child overrides without CSS imports. */
function ludora_child_styles() {
	wp_enqueue_style( 'ludora-theme', get_template_directory_uri() . '/assets/css/theme.css', array(), wp_get_theme( get_template() )->get( 'Version' ) );
	wp_enqueue_style( 'ludora-child', get_stylesheet_uri(), array( 'ludora-theme' ), wp_get_theme()->get( 'Version' ) );
}
add_action( 'wp_enqueue_scripts', 'ludora_child_styles', 20 );
