<?php
/** Pure PHP policy suite; no WordPress bootstrap or database is required. */
$plugin_root = dirname( __DIR__, 2 ) . '/plugins/ludora-booking';
if ( ! is_dir( $plugin_root ) ) {
	$plugin_root = dirname( __DIR__, 2 ) . '/wp-content/plugins/ludora-booking';
}
foreach ( array( 'LocalTime', 'State', 'Engine' ) as $class ) {
	require_once $plugin_root . '/src/Domain/' . $class . '.php';
}
use Ludora\Booking\Domain\Engine;
use Ludora\Booking\Domain\LocalTime;
use Ludora\Booking\Domain\State;

$count = 0;
function check( bool $condition, string $message ): void {
	global $count;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$count;
}
function rejects( callable $callback, string $message ): void {
	try { $callback(); } catch ( DomainException $error ) { check( true, $message ); return; }
	throw new RuntimeException( 'Expected rejection: ' . $message );
}
$room = array(
	'id' => 7, 'name' => 'Test room', 'active' => true, 'timezone' => 'Europe/Madrid', 'currency' => 'EUR', 'precision' => 2,
	'capacity_children' => 20, 'capacity_adults' => 10, 'weekly' => array_fill( 1, 7, array( array( '10:00', '20:00' ) ) ),
	'shifts' => array( 'morning' => array( 'name' => 'Morning', 'start' => '10:00', 'end' => '14:00' ) ),
	'min_duration' => 60, 'max_duration' => 480, 'increment' => 30, 'notice_minutes' => 60, 'horizon_days' => 365,
	'buffer_before' => 15, 'buffer_after' => 30, 'rates' => array( 'hourly' => 4500, 'shift' => 15000, 'day' => 28000 ),
	'date_rates' => array(), 'weekday_rates' => array(), 'closed_dates' => array(), 'opening_exceptions' => array(), 'blocks' => array(), 'tax_basis_points' => 2100,
);
$request = array( 'room_id' => 7, 'date' => '2027-01-18', 'mode' => 'hourly', 'start' => '10:00', 'duration' => 90, 'children' => 5, 'adults' => 2, 'extras' => array() );
$now = 1800000000; // 2027-01-15T08:00:00Z; fixed to keep expected outcomes repeatable.
$quote = Engine::quote( $room, $request, array(), $now );
check( 1800262800 === $quote['start'] && 1800268200 === $quote['end'], 'UTC endpoints' );
check( 1800261900 === $quote['occupancy_start'] && 1800270000 === $quote['occupancy_end'], 'Occupancy margins' );
check( 6750 === $quote['subtotal'] && 1418 === $quote['tax'] && 8168 === $quote['total'], 'Integer prorating and half-up tax' );
check( array( 'room:7' => array( 'quantity' => 1, 'capacity' => 1 ) ) === $quote['resources'], 'Exclusive room claim' );
check( 'EUR' === $quote['currency'] && 2 === $quote['precision'], 'Money metadata' );
check( 1792895400 === LocalTime::parse( '2026-10-25', '03:30', 'Europe/Madrid' ), 'Unambiguous time after fold' );
rejects( fn() => LocalTime::parse( '2027-03-28', '02:30', 'Europe/Madrid' ), 'Spring DST gap' );
rejects( fn() => LocalTime::parse( '2026-10-25', '02:30', 'Europe/Madrid' ), 'Autumn DST fold' );
rejects( fn() => LocalTime::parse( '2027-02-29', '10:00', 'UTC' ), 'Impossible date' );
rejects( fn() => LocalTime::parse( '2027-01-18', '25:00', 'UTC' ), 'Impossible time' );
rejects( fn() => LocalTime::parse( '2027-01-18', '10:00', '+02:00' ), 'IANA timezone required' );
$pricing = $room;
$pricing['weekday_rates'][1]['hourly'] = 6000;
check( 9000 === Engine::quote( $pricing, $request, array(), $now )['subtotal'], 'Weekday price precedence' );
$pricing['date_rates']['2027-01-18']['hourly'] = 8000;
check( 12000 === Engine::quote( $pricing, $request, array(), $now )['subtotal'], 'Date price precedence' );
$exception = $room;
$exception['weekly'][1] = array();
$exception['opening_exceptions']['2027-01-18'] = array( array( '10:00', '12:00' ) );
check( 6750 === Engine::quote( $exception, $request, array(), $now )['subtotal'], 'Opening exception overrides weekly closure' );
$exception['closed_dates'][] = '2027-01-18';
rejects( fn() => Engine::quote( $exception, $request, array(), $now ), 'Closed date overrides exception' );
$exception = $room;
$exception['opening_exceptions']['2027-01-18'] = array();
rejects( fn() => Engine::quote( $exception, $request, array(), $now ), 'Empty exception is closure' );
foreach ( array( array( 'duration', 30 ), array( 'duration', 510 ), array( 'duration', 75 ), array( 'duration', 60.5 ), array( 'start', '10:15' ), array( 'children', 21 ), array( 'adults', 11 ), array( 'children', -1 ), array( 'room_id', 8 ), array( 'mode', 'unknown' ) ) as $change ) {
	$bad = array_replace( $request, array( $change[0] => $change[1] ) );
	rejects( fn() => Engine::quote( $room, $bad, array(), $now ), 'Invalid input: ' . $change[0] );
}
$boundary = array_replace( $request, array( 'start' => '19:00', 'duration' => 60 ) );
check( 3600 === Engine::quote( $room, $boundary, array(), $now )['end'] - Engine::quote( $room, $boundary, array(), $now )['start'], 'End equals closing boundary' );
$boundary['start'] = '19:30';
rejects( fn() => Engine::quote( $room, $boundary, array(), $now ), 'Interval crosses closing boundary' );
check( 6750 === Engine::quote( $room, $request, array(), $quote['start'] - 3600 )['subtotal'], 'Notice equality allowed' );
rejects( fn() => Engine::quote( $room, $request, array(), $quote['start'] - 3599 ), 'Notice one second too short' );
$limited = $room;
$limited['horizon_days'] = 2;
rejects( fn() => Engine::quote( $limited, $request, array(), $now ), 'Beyond calendar horizon' );
$blocked = $room;
$blocked['blocks'][] = array( 'date' => '2027-01-18', 'start' => '11:45', 'end' => '12:00' );
rejects( fn() => Engine::quote( $blocked, $request, array(), $now ), 'Buffer intersects block' );
$blocked['blocks'][0]['start'] = '12:00';
$blocked['blocks'][0]['end'] = '12:30';
check( 6750 === Engine::quote( $blocked, $request, array(), $now )['subtotal'], 'Half-open block adjacency' );
$shift = array_replace( $request, array( 'mode' => 'shift', 'shift' => 'morning' ) );
check( 15000 === Engine::quote( $room, $shift, array(), $now )['subtotal'], 'Shift fixed rate' );
$day = array_replace( $request, array( 'mode' => 'day' ) );
$day_quote = Engine::quote( $room, $day, array(), $now );
check( 28000 === $day_quote['subtotal'] && 36000 === $day_quote['end'] - $day_quote['start'], 'Commercial day, not 24 hours' );
$extras = array(
	array( 'id' => 10, 'name' => 'Cleaning', 'basis' => 'fixed', 'price' => 2500, 'min' => 0, 'max' => 1 ),
	array( 'id' => 11, 'name' => 'Host', 'basis' => 'hour', 'price' => 4000, 'min' => 0, 'max' => 1, 'capacity' => 1 ),
	array( 'id' => 12, 'name' => 'Snacks', 'basis' => 'attendee', 'price' => 800, 'min' => 0, 'max' => 100 ),
	array( 'id' => 13, 'name' => 'Plates', 'basis' => 'quantity', 'price' => 200, 'min' => 2, 'max' => 100, 'capacity' => 20 ),
);
$with_extras = array_replace( $request, array( 'extras' => array( 10 => 1, 11 => 1, 12 => 1, 13 => 3 ) ) );
$extra_quote = Engine::quote( $room, $with_extras, $extras, $now );
check( array( 6750, 2500, 6000, 4000, 600 ) === array_column( $extra_quote['lines'], 'net' ), 'Each extra basis billed exactly once' );
check( 19850 === $extra_quote['subtotal'] && 4169 === $extra_quote['tax'] && 24019 === $extra_quote['total'], 'Line tax summed once' );
check( array( 'quantity' => 1, 'capacity' => 1 ) === $extra_quote['resources']['extra:11'] && array( 'quantity' => 3, 'capacity' => 20 ) === $extra_quote['resources']['extra:13'], 'Limited extras share unified claims' );
$bad = array_replace( $request, array( 'extras' => array( 12 => 5 ) ) );
rejects( fn() => Engine::quote( $room, $bad, $extras, $now ), 'Attendee quantity cannot multiply attendance twice' );
$bad['extras'] = array( 999 => 1 );
rejects( fn() => Engine::quote( $room, $bad, $extras, $now ), 'Unknown extras' );
$extras[0]['required'] = true;
check( 9250 === Engine::quote( $room, $request, $extras, $now )['subtotal'], 'Required extra automatically included' );
$extras[0]['rooms'] = array( 8 );
$bad['extras'] = array( 10 => 1 );
rejects( fn() => Engine::quote( $room, $bad, $extras, $now ), 'Extra room restriction' );
check( 'confirmed' === State::transition( 'pending', 'confirmed' ), 'Reservation confirmation' );
check( 'rejected' === State::transition( 'pending', 'rejected' ), 'Request can be declined' );
check( 'completed' === State::transition( 'confirmed', 'completed' ), 'Confirmed reservation can complete' );
rejects( fn() => State::transition( 'completed', 'confirmed' ), 'Completed reservation is terminal' );
rejects( fn() => State::transition( 'rejected', 'confirmed' ), 'Rejected reservation is terminal' );
check( 'refunded' === State::transition( 'paid', 'refunded', true ), 'Independent payment refund' );
rejects( fn() => State::transition( 'cancelled', 'confirmed' ), 'Cancelled reservations cannot revive' );
rejects( fn() => State::transition( 'paid', 'pending', true ), 'Paid payment cannot regress' );
check( State::blocks( 'pending', 101, 100 ) && ! State::blocks( 'pending', 100, 100 ) && State::blocks( 'confirmed', null, 100 ) && ! State::blocks( 'cancelled', null, 100 ), 'State expiry boundary' );
check( ! Engine::overlaps( 10, 20, 20, 30 ) && Engine::overlaps( 10, 21, 20, 30 ), 'Half-open overlap helper' );
echo "PASS: $count domain assertions\n";
