<?php
require '/var/www/html/wp-load.php';
use Ludora\Booking\Demo;
use Ludora\Booking\Store;
global $wpdb;
$original_settings = Ludora\Booking\Settings::get();
$store = new Store(); $posts = array(); $bookings = array(); $count = 0;
function late_check( bool $ok, string $label ): void { global $count; if ( ! $ok ) { throw new RuntimeException( $label ); } $count++; echo "PASS: $label\n"; }
function late_room(): array {
 global $posts;
 $id = wp_insert_post( array( 'post_type' => 'ludora_room', 'post_title' => 'Late policy fixture', 'post_status' => 'publish' ) ); $posts[] = $id;
 $config = Demo::configuration( 'ludora_room' ); $config['buffer_after'] = 0; update_post_meta( $id, '_ludora_config', $config );
 return array( $id, $config );
}
function late_create( int $room, array $extras = array(), string $start = '10:00' ): array {
 global $store, $bookings;
 $r = array( 'room_id' => $room, 'date' => wp_date( 'Y-m-d', time() + 55 * DAY_IN_SECONDS ), 'mode' => 'hourly', 'start' => $start, 'duration' => 120, 'children' => 5, 'adults' => 2, 'extras' => $extras );
 $row = $store->create( $r, array( 'name' => 'Late policy test', 'email' => 'late-policy@example.test' ), hash( 'sha256', random_bytes( 32 ) ), bin2hex( random_bytes( 32 ) ), true ); $bookings[] = $row['id']; return $row;
}
function late_expire( array $row ): void { global $wpdb; $wpdb->update( $wpdb->prefix . 'ludora_bookings', array( 'expires_at' => time() - 1 ), array( 'id' => $row['id'] ) ); }
try {
 [$id,$config] = late_room(); $row = late_create( $id ); late_expire( $row ); $config['closed_dates'][] = json_decode( $row['request'], true )['date']; update_post_meta( $id, '_ludora_config', $config );
 $paid = $store->payment( (int) $row['id'], 'paid', 810001 ); late_check( 'paid' === $paid['payment_state'] && 'confirmed' !== $paid['state'], 'late paid callback cannot confirm a newly closed date' );
 late_check( in_array( 'paid_inventory_conflict', array_column( $store->history( (int) $row['id'] ), 'event' ), true ), 'closed-date payment creates a venue incident' );
 [$a,$config] = late_room(); [$b] = late_room();
 $extra = wp_insert_post( array( 'post_type' => 'ludora_extra', 'post_title' => 'Late extra capacity fixture', 'post_status' => 'publish' ) ); $posts[] = $extra;
 $ec = Demo::configuration( 'ludora_extra' ); $ec['capacity'] = 2; update_post_meta( $extra, '_ludora_config', $ec );
 $old = late_create( $a, array( $extra => 1 ) ); late_expire( $old ); $other = late_create( $b, array( $extra => 1 ) ); $ec['capacity'] = 1; update_post_meta( $extra, '_ludora_config', $ec );
 $paid = $store->payment( (int) $old['id'], 'paid', 810002 ); late_check( 'confirmed' !== $paid['state'], 'late reacquisition respects reduced current extra capacity across rooms' );
 [$id,$config] = late_room(); $old = late_create( $id ); late_expire( $old ); $other = late_create( $id, array(), '12:00' ); $config['buffer_after'] = 30; update_post_meta( $id, '_ludora_config', $config );
 $paid = $store->payment( (int) $old['id'], 'paid', 810003 ); late_check( 'confirmed' !== $paid['state'], 'late reacquisition uses current cleaning margins' );
 [$id,$config] = late_room(); $old = late_create( $id ); late_expire( $old ); $config['rates']['hourly'] = 9900; update_post_meta( $id, '_ludora_config', $config );
 $paid = $store->payment( (int) $old['id'], 'paid', 810004 ); late_check( 'confirmed' === $paid['state'], 'an available expired hold can reacquire inventory atomically' );
 late_check( json_decode( $paid['snapshot'], true )['total'] === json_decode( $old['snapshot'], true )['total'], 'late reacquisition preserves original financial snapshot after rate changes' );
 $config['rates']['hourly'] = 4500; update_post_meta( $id, '_ludora_config', $config );
 update_option( 'ludora_settings', array_merge( $original_settings, array( 'precision' => 3 ) ) );
 $rejected = false; try { $r = json_decode( $paid['request'], true ); $r['start'] = '14:00'; $store->reschedule( (int) $paid['id'], $r ); } catch ( DomainException $e ) { $rejected = true; }
 late_check( $rejected && $store->get( (int) $paid['id'] )['start_utc'] === $paid['start_utc'], 'paid reschedule rejects changed currency precision even when minor-unit integers match' );
 echo "LATE POLICY PASS: $count assertions\n";
} finally {
 update_option( 'ludora_settings', $original_settings );
 foreach ( $bookings as $id ) { foreach ( array( 'claims', 'events', 'outbox', 'bookings' ) as $table ) { $wpdb->delete( $wpdb->prefix . 'ludora_' . $table, array( 'bookings' === $table ? 'id' : 'booking_id' => $id ) ); } }
 foreach ( $posts as $id ) { wp_delete_post( $id, true ); foreach ( array( 'room:', 'extra:' ) as $kind ) { $wpdb->delete( $wpdb->prefix . 'ludora_resources', array( 'resource_key' => $kind . $id ) ); } }
}
