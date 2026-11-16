<?php
require '/var/www/html/wp-load.php';
use Ludora\Booking\Demo;
use Ludora\Booking\Store;
function check( bool $ok, string $label ): void { if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); } echo "PASS: $label\n"; }
function rejected( callable $fn, string $label ): void { try { $fn(); } catch ( DomainException | InvalidArgumentException $e ) { check( true, $label ); return; } throw new RuntimeException( 'FAIL (expected rejection): ' . $label ); }
$store = new Store(); global $wpdb; $prefix = $wpdb->prefix . 'ludora_';
$id = wp_insert_post( array( 'post_type' => 'ludora_room', 'post_title' => 'Integration-only room', 'post_status' => 'publish' ) );
$config = Demo::configuration( 'ludora_room' ); $config['buffer_after'] = 0; $config['notice_minutes'] = 0;
update_post_meta( $id, '_ludora_config', $config );
$request = array( 'room_id' => $id, 'date' => wp_date( 'Y-m-d', time() + 20 * DAY_IN_SECONDS, new DateTimeZone( 'Europe/Madrid' ) ), 'mode' => 'hourly', 'start' => '10:00', 'duration' => 120, 'children' => 10, 'adults' => 2, 'extras' => array() );
$customer = array( 'name' => 'Test Adult', 'email' => 'integration@example.test', 'phone' => '', 'terms' => true ); $created = array();
try {
 $token = bin2hex( random_bytes( 32 ) ); $key = hash( 'sha256', random_bytes( 32 ) );
 $a = $store->create( $request, $customer, $key, $token ); $created[] = $a['id'];
 check( 'pending' === $a['state'] && 'pending' === $a['payment_state'], 'manual request remains pending and unpaid' );
 check( $a['id'] === $store->create( $request, $customer, $key, $token )['id'], 'idempotent retry returns same reservation' );
 rejected( fn() => $store->create( array_merge( $request, array( 'duration' => 180 ) ), $customer, $key, $token ), 'retry key cannot mutate reservation' );
 rejected( fn() => $store->create( $request, $customer, hash( 'sha256', random_bytes( 32 ) ), bin2hex( random_bytes( 32 ) ) ), 'same room and interval conflicts' );
 $adjacent = array_merge( $request, array( 'start' => '12:00' ) );
 $b = $store->create( $adjacent, $customer, hash( 'sha256', random_bytes( 32 ) ), bin2hex( random_bytes( 32 ) ) ); $created[] = $b['id'];
 check( (int) $b['id'] !== (int) $a['id'], 'half-open adjacent reservations allowed without margin' );
 rejected( fn() => $store->reschedule( (int) $a['id'], $adjacent ), 'conflicting reschedule rejected' );
 check( (int) $store->get( (int) $a['id'] )['start_utc'] === (int) $a['start_utc'], 'failed reschedule preserves original interval' );
 check( null === $store->by_token( str_repeat( '0', 64 ) ), 'unknown private token cannot access a reservation' );
 check( $store->by_token( $token )['id'] === $a['id'], 'valid token accesses intended reservation' );
 $wpdb->update( $prefix . 'bookings', array( 'expires_at' => time() - 1 ), array( 'id' => $a['id'] ) );
 $c = $store->create( $request, $customer, hash( 'sha256', random_bytes( 32 ) ), bin2hex( random_bytes( 32 ) ) ); $created[] = $c['id'];
 check( (int) $c['id'] !== (int) $a['id'], 'expiration frees capacity without cron' );
 rejected( fn() => $store->transition( (int) $a['id'], 'confirmed' ), 'late confirmation cannot override replacement' );
 $store->transition( (int) $b['id'], 'cancelled' ); check( 'pending' === $store->get( (int) $b['id'] )['payment_state'], 'cancellation does not fabricate refund' );
 echo "INTEGRATION PASS\n";
} finally {
 foreach ( $created as $booking ) { foreach ( array( 'claims', 'events', 'outbox', 'bookings' ) as $table ) { $wpdb->delete( $prefix . $table, array( 'bookings' === $table ? 'id' : 'booking_id' => $booking ) ); } }
 wp_delete_post( $id, true );
}
