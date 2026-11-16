<?php
ob_start();
require '/var/www/html/wp-load.php';
global $wpdb;
$room = wp_insert_post( array( 'post_type' => 'ludora_room', 'post_title' => 'CSV historical fixture', 'post_status' => 'publish' ) ); $ids = array();
try {
 $nonce = bin2hex( random_bytes( 6 ) );
 for ( $i = 0; $i < 1005; $i++ ) {
  $key = hash( 'sha256', $nonce . $i );
  $ok = $wpdb->insert( $wpdb->prefix . 'ludora_bookings', array( 'reference' => 'CSV-' . $nonce . '-' . $i, 'token_hash' => $key, 'idempotency' => $key, 'fingerprint' => $key, 'room_id' => $room, 'start_utc' => 1600000000 + $i, 'end_utc' => 1600001000 + $i, 'state' => 'completed', 'payment_state' => 'pending', 'customer' => wp_json_encode( array( 'name' => '=SUM(A1:A2)', 'email' => 'csv@example.test' ) ), 'request' => '{}', 'snapshot' => '{"total":0,"currency":"EUR","precision":2}', 'created_at' => 1600000000, 'updated_at' => 1600000000 ) );
  if ( ! $ok ) { throw new RuntimeException( 'Historical fixture insert failed.' ); } $ids[] = $wpdb->insert_id;
 }
 $store = new Ludora\Booking\Store();
 if ( count( $store->listing( array( 'room_id' => $room ) ) ) !== 1000 || count( $store->listing( array( 'room_id' => $room ), 1000, 1000 ) ) !== 5 ) { throw new RuntimeException( 'Administrative pagination failed.' ); }
 echo "PASS: list pagination returns1000 +5 historical records\n";
 wp_set_current_user( 1 ); $_POST = array( 'room_id' => $room ); $_REQUEST['_wpnonce'] = wp_create_nonce( 'ludora_action' );
 $export = new ReflectionMethod( Ludora\Booking\Admin::class, 'export' ); ob_start(); $export->invoke( null, $store ); $csv = ob_get_clean();
 $lines = preg_split( '/\r?\n/', trim( $csv ) );
 if ( count( $lines ) !== 1006 ) { throw new RuntimeException( 'Export truncated historical records.' ); }
 echo "PASS: actual administrative CSV includes all1005 rows plus header\n";
 $references = array(); foreach ( array_slice( $lines, 1 ) as $line ) { $fields = str_getcsv( $line, ',', '"', '' ); $references[] = $fields[0]; if ( ! str_starts_with( $fields[6], "'=" ) ) { throw new RuntimeException( 'Formula escaping failed.' ); } }
 if ( count( array_unique( $references ) ) !== 1005 ) { throw new RuntimeException( 'Duplicate CSV rows.' ); }
 echo "PASS: exported references unique and all formula-like names escaped\nCSV VOLUME PASS\n";
} finally { foreach ( $ids as $id ) { $wpdb->delete( $wpdb->prefix . 'ludora_bookings', array( 'id' => $id ) ); } wp_delete_post( $room, true ); }
