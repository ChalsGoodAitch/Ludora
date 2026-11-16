<?php
/** Run against real WordPress: docker compose exec -T wordpress php ludora-tests/integration/configuration.php */
require '/var/www/html/wp-load.php';
use Ludora\Booking\ConfigurationForm;
use Ludora\Booking\Demo;
function ludora_configuration_check( bool $ok, string $label ): void {
	if ( ! $ok ) { throw new RuntimeException( 'FAIL: ' . $label ); }
	echo 'PASS: ' . $label . "\n";
}
function ludora_configuration_reject( array $input, string $label ): void {
	try { ConfigurationForm::normalize( $input, 'ludora_room' ); }
	catch ( DomainException $error ) { ludora_configuration_check( true, $label ); return; }
	throw new RuntimeException( 'FAIL: accepted ' . $label );
}
$config = Demo::configuration( 'ludora_room' );
$config['date_rates'] = array( '2026-12-24' => array( 'day' => 30000 ) );
$fields = $config;
for ( $day = 1; $day <= 7; $day++ ) { $fields[ 'weekly_' . $day ] = '10:00-14:00, 16:00-20:00'; }
foreach ( $config['rates'] as $mode => $price ) { $fields[ 'rate_' . $mode ] = $price; }
$fields['shifts'] = "morning|Morning|10:00|14:00\nafternoon|Afternoon|16:00|20:00";
$fields['closed_dates'] = '2026-12-25';
$input = array( 'ludora_config' => wp_json_encode( $config ), 'ludora_guided' => 1, 'ludora_fields' => $fields );
$saved = ConfigurationForm::normalize( $input, 'ludora_room' );
ludora_configuration_check( array( array( '10:00', '14:00' ), array( '16:00', '20:00' ) ) === $saved['weekly'][1], 'multiple local opening intervals roundtrip' );
ludora_configuration_check( $saved['date_rates'] === $config['date_rates'], 'guided save retains advanced date rates' );
ludora_configuration_check( array( '2026-12-25' ) === $saved['closed_dates'], 'closed dates roundtrip' );
foreach ( array( 'weekly_1' => '20:00-10:00', 'closed_dates' => '2026-02-30', 'increment' => '0', 'rate_hourly' => '2.5', 'shifts' => 'bad|Shift|25:00|26:00' ) as $field => $value ) {
	$bad = $input; $bad['ludora_fields'][ $field ] = $value;
	ludora_configuration_reject( $bad, 'reject invalid ' . $field );
}
$extra = array( 'ludora_config' => wp_json_encode( Demo::configuration( 'ludora_extra' ) ), 'ludora_guided' => 1, 'ludora_fields' => array( 'basis' => 'attendee', 'attendee_basis' => 'all', 'price' => '800', 'min' => '0', 'max' => '1', 'capacity' => '0', 'rooms' => '12, 34', 'mode_hourly' => 1 ) );
$saved = ConfigurationForm::normalize( $extra, 'ludora_extra' );
ludora_configuration_check( array( 12, 34 ) === $saved['rooms'] && array( 'hourly' ) === $saved['modes'] && 'all' === $saved['attendee_basis'], 'extra restrictions and attendee basis roundtrip' );
$room_id = wp_insert_post( array( 'post_type' => 'ludora_room', 'post_title' => 'Configuration test room', 'post_status' => 'draft' ) );
$extra_id = wp_insert_post( array( 'post_type' => 'ludora_extra', 'post_title' => 'Configuration test extra', 'post_status' => 'draft' ) );
try {
	$package = array( 'ludora_config' => '{}', 'ludora_guided' => 1, 'ludora_fields' => array( 'room_id' => (string) $room_id, 'mode' => 'shift', 'shift' => 'afternoon', 'duration' => '120', 'extra_' . $extra_id => '1' ) );
	$saved = ConfigurationForm::normalize( $package, 'ludora_package' );
	ludora_configuration_check( $room_id === $saved['room_id'] && 1 === $saved['extras'][ $extra_id ], 'package references real catalog items' );
} finally { wp_delete_post( $room_id, true ); wp_delete_post( $extra_id, true ); }
echo "CONFIGURATION PASS\n";
