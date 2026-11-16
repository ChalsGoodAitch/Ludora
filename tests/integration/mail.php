<?php
ob_start();
require '/var/www/html/wp-load.php';
use Ludora\Booking\Demo;
use Ludora\Booking\Mail;
use Ludora\Booking\Store;

function mail_assert( bool $value, string $label ): void { if ( ! $value ) { throw new RuntimeException( $label ); } echo "PASS: $label\n"; }
function captured( string $reference ): array {
 $response = wp_remote_get( 'http://mailpit:8025/api/v1/messages?limit=1000' );
 if ( is_wp_error( $response ) ) { throw new RuntimeException( 'Mailpit unavailable.' ); }
 $all = json_decode( wp_remote_retrieve_body( $response ), true );
 return array_values( array_filter( $all['messages'], static fn( $m ) => str_contains( $m['Subject'], $reference ) ) );
}
$id = wp_insert_post( array( 'post_type' => 'ludora_room', 'post_title' => 'Notification test room', 'post_status' => 'publish' ) );
update_post_meta( $id, '_ludora_config', Demo::configuration( 'ludora_room' ) );
$store = new Store(); $booking_id = 0;
try {
 $request = array( 'room_id' => $id, 'date' => wp_date( 'Y-m-d', time() + 35 * DAY_IN_SECONDS ), 'mode' => 'hourly', 'start' => '10:00', 'duration' => 120, 'children' => 8, 'adults' => 2, 'extras' => array() );
 $key = hash( 'sha256', random_bytes( 32 ) ); $token = Store::access_token( $key );
 $customer = array( 'name' => 'Mail Test Adult', 'email' => 'notification-test@example.test', 'phone' => '', 'terms' => true );
 $row = $store->create( $request, $customer, $key, $token ); $booking_id = (int) $row['id'];
 $store->create( $request, $customer, $key, $token );
 $fail_business = true;
 $intercept = static function ( $pre, $data ) use ( &$fail_business, $row ) {
  if ( $fail_business && str_contains( $data['subject'], $row['reference'] ) && $data['to'] !== 'notification-test@example.test' ) { $fail_business = false; return false; }
  return $pre;
 };
 add_filter( 'pre_wp_mail', $intercept, 10, 2 );
 Mail::flush(); mail_assert( count( captured( $row['reference'] ) ) === 1, 'simulated business-recipient failure leaves one customer receipt' );
 Mail::flush(); remove_filter( 'pre_wp_mail', $intercept, 10 );

 $messages = captured( $row['reference'] ); mail_assert( count( $messages ) === 2, 'request retry delivers exactly one customer and one business receipt' );
 foreach ( $messages as $message ) {
  $body = json_decode( wp_remote_retrieve_body( wp_remote_get( 'http://mailpit:8025/api/v1/message/' . rawurlencode( $message['ID'] ) ) ), true );
  mail_assert( str_contains( $body['Text'], 'not a payment receipt or confirmation' ), 'request text never claims payment or confirmation' );
  mail_assert( str_contains( $body['Text'], '#booking=' . $token ), 'captured message has valid private status link without exposing it in test output' );
 }
 $store->transition( $booking_id, 'confirmed' ); $store->transition( $booking_id, 'confirmed' ); Mail::flush(); Mail::flush();
 mail_assert( count( captured( $row['reference'] ) ) === 4, 'repeated confirmation adds exactly two notifications' );
 $store->transition( $booking_id, 'cancelled' ); $store->transition( $booking_id, 'cancelled' ); Mail::flush(); Mail::flush();
 $messages = captured( $row['reference'] ); mail_assert( count( $messages ) === 6, 'repeated cancellation adds exactly two notifications' );
 $cancel = array_values( array_filter( $messages, static fn( $m ) => str_contains( $m['Subject'], 'cancelled' ) ) );
 mail_assert( count( $cancel ) === 2, 'customer and business cancellation subjects captured' );
 $body = json_decode( wp_remote_retrieve_body( wp_remote_get( 'http://mailpit:8025/api/v1/message/' . rawurlencode( $cancel[0]['ID'] ) ) ), true );
 mail_assert( str_contains( $body['Text'], 'does not automatically refund' ), 'cancellation explains separate refunds' );
 echo "MAIL CAPTURE PASS (synthetic recipients only). SMTP transport crash exactly-once semantics are not claimed.\n";
} finally {
 global $wpdb;
 if ( $booking_id ) { foreach ( array( 'claims', 'events', 'outbox', 'bookings' ) as $table ) { $wpdb->delete( $wpdb->prefix . 'ludora_' . $table, array( 'bookings' === $table ? 'id' : 'booking_id' => $booking_id ) ); } }
 $wpdb->delete( $wpdb->prefix . 'ludora_resources', array( 'resource_key' => 'room:' . $id ) ); wp_delete_post( $id, true );
}
