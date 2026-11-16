<?php
/** Explicit development-only layout refresh; not part of the idempotent customer importer. */
require '/var/www/html/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Local demonstration only.' ); }
$photos = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 2, 'meta_key' => '_ludora_demo_photo', 'orderby' => 'ID', 'order' => 'ASC' ) );
$method = new ReflectionMethod( Ludora\Booking\Demo::class, 'home' );
$image_method = new ReflectionMethod( Ludora\Booking\Demo::class, 'image' );
foreach ( array( 'en', 'es' ) as $lang ) {
 $gallery = get_posts( array( 'post_type' => 'page', 'numberposts' => 1, 'meta_key' => '_ludora_demo_key', 'meta_value' => $lang . ':page-gallery' ) );
 if ( $gallery ) { wp_update_post( array( 'ID' => $gallery[0]->ID, 'post_content' => $image_method->invoke( null, $photos[0]->ID ) . $image_method->invoke( null, $photos[1]->ID ) ) ); }
 for ( $room = 0; $room < 3; $room++ ) {
  $posts = get_posts( array( 'post_type' => 'ludora_room', 'numberposts' => 1, 'meta_key' => '_ludora_demo_key', 'meta_value' => $lang . ':room-' . $room ) );
  if ( ! $posts ) { continue; }
  $blocks = parse_blocks( $posts[0]->post_content ); $paragraph = '';
  foreach ( $blocks as $block ) { if ( 'core/paragraph' === $block['blockName'] ) { $paragraph = serialize_block( $block ); break; } }
  wp_update_post( array( 'ID' => $posts[0]->ID, 'post_content' => $paragraph . $image_method->invoke( null, $photos[0]->ID ) . $image_method->invoke( null, $photos[1]->ID ) ) );
 }
}
foreach ( array( 'play-park', 'celebration-room', 'birthday-experiences' ) as $i => $key ) {
 foreach ( array( 'en', 'es' ) as $lang ) {
  $posts = get_posts( array( 'post_type' => 'page', 'numberposts' => 1, 'meta_key' => '_ludora_demo_key', 'meta_value' => $lang . ':home-' . $key ) );
  if ( ! $posts ) { continue; }
  $id = $posts[0]->ID;
  wp_update_post( array( 'ID' => $id, 'post_content' => $method->invoke( null, $posts[0]->post_title, $i, 'es' === $lang, $photos[ $i % count( $photos ) ]->ID, $photos[ ( $i + 1 ) % count( $photos ) ]->ID ) ) );
  update_post_meta( $id, '_wp_page_template', 'landing' );
  echo "Refreshed generated local demo layout: $id\n";
 }
}
