<?php
require '/var/www/html/wp-load.php';
$id = wp_insert_post( array( 'post_type' => 'ludora_room', 'post_title' => 'Concurrency-only room', 'post_status' => 'publish' ) );
$c = Ludora\Booking\Demo::configuration( 'ludora_room' ); update_post_meta( $id, '_ludora_config', $c );
$nonce = bin2hex( random_bytes( 8 ) ); $processes = array();
try {
 for ( $i = 0; $i < 2; $i++ ) { $pipes = array(); $process = proc_open( array( PHP_BINARY, __DIR__ . '/concurrency-worker.php', (string) $id, $nonce ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes ); $processes[] = array( $process, $pipes ); }
 usleep( 300000 ); touch( '/tmp/ludora-concurrency-' . $nonce );
 $outputs = array(); foreach ( $processes as [ $process, $pipes ] ) { fclose( $pipes[0] ); $outputs[] = stream_get_contents( $pipes[1] ); $errors = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); if ( proc_close( $process ) !== 0 ) { throw new RuntimeException( 'Worker failed: ' . $errors ); } }
 sort( $outputs ); if ( $outputs !== array( 'CONFLICT', 'OCCUPIED' ) ) { throw new RuntimeException( 'Concurrency failed: ' . wp_json_encode( $outputs ) ); }
 echo "PASS: Two independent PHP processes with real InnoDB: exactly one occupied, one conflicted.\n";
} finally {
 global $wpdb; $prefix = $wpdb->prefix . 'ludora_'; $bookings = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$prefix}bookings WHERE room_id=%d", $id ) );
 foreach ( $bookings as $booking ) { foreach ( array( 'claims', 'events', 'outbox', 'bookings' ) as $table ) { $wpdb->delete( $prefix . $table, array( 'bookings' === $table ? 'id' : 'booking_id' => $booking ) ); } }
 wp_delete_post( $id, true ); @unlink( '/tmp/ludora-concurrency-' . $nonce );
}
