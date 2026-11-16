<?php
require '/var/www/html/wp-load.php';
$id = (int) $argv[1]; $nonce = $argv[2];
$request = array( 'room_id' => $id, 'date' => wp_date( 'Y-m-d', time() + 30 * DAY_IN_SECONDS, new DateTimeZone( 'Europe/Madrid' ) ), 'mode' => 'hourly', 'start' => '10:00', 'duration' => 120, 'children' => 5, 'adults' => 2, 'extras' => array() );
$barrier = '/tmp/ludora-concurrency-' . preg_replace( '/[^a-zA-Z0-9]/', '', $nonce ); $deadline = microtime( true ) + 20;
while ( ! file_exists( $barrier ) ) { if ( microtime( true ) > $deadline ) { fwrite( STDERR, 'Barrier timeout' ); exit( 2 ); } usleep( 10000 ); }
try { ( new Ludora\Booking\Store() )->create( $request, array( 'name' => 'Concurrent Test', 'email' => 'concurrency@example.test' ), hash( 'sha256', random_bytes( 32 ) ), bin2hex( random_bytes( 32 ) ) ); echo 'OCCUPIED'; }
catch ( DomainException $e ) { echo 'CONFLICT'; }
