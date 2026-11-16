<?php
/**
 * Idempotent localized demonstration content and licensed media import.
 *
 * @package Ludora\Booking
 */

namespace Ludora\Booking;

defined( 'ABSPATH' ) || exit;

/** Idempotent localized demonstration content and licensed media import. */
final class Demo {
	/**
	 * Render the voluntary demo importer and last change report.
	 */
	public static function page(): void {
		if ( ! current_user_can( 'manage_ludora' ) ) {
			return; }
		echo '<div class="wrap"><h1>' . esc_html__( 'Import an editable venue demo', 'ludora-booking' ) . '</h1><p>' . esc_html__( 'Creates three sample rooms, extras, packages and pages in your chosen language. All prices and venue details are fictional demonstration data. Existing content is never overwritten; repeating an import skips already imported items. Nothing is imported on theme activation.', 'ludora-booking' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ludora_action"><input type="hidden" name="operation" value="demo">';
		wp_nonce_field( 'ludora_action' );
		echo '<p><label>' . esc_html__( 'Demo language', 'ludora-booking' ) . ' <select name="language"><option value="en">English</option><option value="es">Español</option></select></label></p><p><label><input type="checkbox" name="set_home" value="1">' . esc_html__( 'Set the imported play park page as the homepage and the imported journal as the blog (replaces Reading settings only).', 'ludora-booking' ) . '</label></p><button class="button button-primary">' . esc_html__( 'Import demo content', 'ludora-booking' ) . '</button></form>';
		$report = get_option( 'ludora_demo_report', array() );
		if ( $report ) {
			echo '<h2>' . esc_html__( 'Last import report', 'ludora-booking' ) . '</h2><ul>';
			foreach ( $report as $item ) {
				echo '<li>' . esc_html( $item ) . '</li>';
			} echo '</ul>';
		} echo '</div>';
	}
	/**
	 * Provide editable example configuration for a new catalogue item.
	 *
	 * @param string $type Catalogue post type.
	 * @return array
	 */
	public static function configuration( string $type ): array {
		if ( 'ludora_extra' === $type ) {
			return array(
				'basis'    => 'fixed',
				'price'    => 1500,
				'min'      => 0,
				'max'      => 1,
				'required' => false,
				'rooms'    => array(),
				'modes'    => array( 'hourly', 'shift', 'day' ),
				'capacity' => 0,
			); }
		if ( 'ludora_package' === $type ) {
			return array(
				'room_id' => 0,
				'mode'    => 'shift',
				'shift'   => 'afternoon',
				'extras'  => array(),
			); }
		$weekly = array(); for ( $day = 1; $day <= 7; $day++ ) {
			$weekly[ $day ] = array( array( '10:00', '20:00' ) ); }
		return array(
			'active'             => true,
			'capacity_children'  => 25,
			'capacity_adults'    => 20,
			'weekly'             => $weekly,
			'shifts'             => array(
				'morning'   => array(
					'name'  => 'Morning',
					'start' => '10:00',
					'end'   => '14:00',
				),
				'afternoon' => array(
					'name'  => 'Afternoon',
					'start' => '16:00',
					'end'   => '20:00',
				),
			),
			'min_duration'       => 60,
			'max_duration'       => 480,
			'increment'          => 30,
			'notice_minutes'     => 60,
			'horizon_days'       => 365,
			'buffer_before'      => 0,
			'buffer_after'       => 30,
			'rates'              => array(
				'hourly' => 4500,
				'day'    => 28000,
				'shift'  => 15000,
			),
			'weekday_rates'      => array(),
			'date_rates'         => array(),
			'closed_dates'       => array(),
			'opening_exceptions' => array(),
			'blocks'             => array(),
			'equipment'          => 'Play area, dining tables, accessible toilet, refrigerator.',
			'rules'              => 'Adult supervision is required. Follow the posted venue rules.',
		);
	}
	/**
	 * Create missing localized demonstration entities without replacing existing content.
	 *
	 * @param string $lang Supported demo language.
	 * @param bool   $set_home Explicitly replace Reading settings.
	 * @return array Import change report.
	 * @throws \DomainException When the caller lacks permission.
	 * @throws \InvalidArgumentException When language is unsupported.
	 */
	public static function import( string $lang = 'en', bool $set_home = false ): array {
		if ( ! current_user_can( 'manage_ludora' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			throw new \DomainException( 'Permission denied.' ); }
		if ( ! in_array( $lang, array( 'en', 'es' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported demo language.' ); }
		return ( new Store() )->transaction(
			function () use ( $lang, $set_home ) {
				$es         = 'es' === $lang;
				$report     = array();
				$ids        = array();
				$photos     = array( self::photo( 'ball-pit.jpg', $lang, $report ), self::photo( 'playground.jpg', $lang, $report ) );
				$room_names = $es ? array( 'Sala Coral', 'Sala Turquesa', 'Sala Lavanda' ) : array( 'Coral Room', 'Turquoise Room', 'Lavender Room' );
				foreach ( $room_names as $i => $name ) {
						$c                  = self::configuration( 'ludora_room' );
					$c['capacity_children'] = 20 + $i * 5;
					if ( $es ) {
						$c['equipment']                   = 'Zona de juegos, mesas para comer, aseo accesible y frigorífico.';
						$c['rules']                       = 'Los menores deben estar supervisados por adultos. Consulta las normas del local.';
						$c['shifts']['morning']['name']   = 'Mañana';
						$c['shifts']['afternoon']['name'] = 'Tarde'; }
					$text           = $es ? 'Un espacio privado para jugar y celebrar con tu familia. Contenido y precios ficticios de demostración. Sustituye las imágenes de ejemplo por fotografías autorizadas del negocio.' : 'A private space to play and celebrate with your family. Fictional demonstration content and prices. Replace sample imagery with authorized photographs of your venue.';
					$id             = self::item( 'room-' . $i, $lang, 'ludora_room', $name, 'room-' . ( $i + 1 ) . '-' . $lang, self::paragraph( $text ) . self::image( $photos[0] ) . self::image( $photos[1] ), $report, $c, 'publish', $photos[ $i % 2 ] );
					$ids['rooms'][] = $id;
				}
				$extra_names = $es ? array( 'Limpieza', 'Animación', 'Decoración', 'Merienda', 'Tarta', 'Menaje' ) : array( 'Cleaning', 'Party host', 'Decorations', 'Snack menu', 'Cake', 'Tableware' );
				foreach ( $extra_names as $i => $name ) {
					$c          = self::configuration( 'ludora_extra' );
					$c['price'] = array( 2500, 4000, 3500, 800, 3500, 200 )[ $i ];
					$c['basis'] = array( 'fixed', 'hour', 'fixed', 'attendee', 'quantity', 'quantity' )[ $i ];
					$c['max']   = in_array( $i, array( 3, 5 ), true ) ? 100 : 1;
					if ( 1 === $i ) {
									$c['capacity'] = 1;
					} $ids['extras'][] = self::item( 'extra-' . $i, $lang, 'ludora_extra', $name, 'extra-' . $i . '-' . $lang, self::paragraph( $es ? 'Servicio de demostración editable. La disponibilidad y el precio se calculan con la reserva.' : 'Editable demo service. Availability and price are calculated with your reservation.' ), $report, $c ); }
				foreach ( $ids['rooms'] as $i => $room ) {
					$c            = self::configuration( 'ludora_package' );
					$c['room_id'] = $room;
					$c['extras']  = array(
						$ids['extras'][0] => 1,
						$ids['extras'][2] => 1,
					);
					self::item( 'package-' . $i, $lang, 'ludora_package', ( $es ? 'Cumpleaños ' : 'Birthday ' ) . $room_names[ $i ], 'birthday-' . $i . '-' . $lang, self::paragraph( $es ? 'Incluye sala en turno de tarde, limpieza y decoración. Los precios se calculan en el motor de reservas con la fecha elegida.' : 'Includes an afternoon room rental, cleaning and decorations. The booking engine calculates the price for your selected date.' ) . '<!-- wp:ludora/booking /-->', $report, $c ); }
				$pages = array(
					'booking'            => array( $es ? 'Disponibilidad y reserva' : 'Availability and booking', '<!-- wp:ludora/booking /-->' ),
					'rooms'              => array( $es ? 'Nuestras salas' : 'Our rooms', '<!-- wp:ludora/rooms /-->' ),
					'extras'             => array( $es ? 'Extras y servicios' : 'Extras and services', '<!-- wp:ludora/extras /-->' ),
					'packages'           => array( $es ? 'Paquetes de cumpleaños' : 'Birthday packages', '<!-- wp:ludora/packages /-->' ),
					'about'              => array( $es ? 'Quiénes somos' : 'About us', self::paragraph( $es ? 'Somos un negocio ficticio de demostración para celebraciones infantiles. Sustituye este texto por la historia y el equipo de tu local.' : 'We are a fictional demonstration children’s party venue. Replace this text with your venue’s story and team.' ) ),
					'pricing'            => array( $es ? 'Tarifas y modalidades' : 'Pricing and rental modes', self::paragraph( $es ? 'Elige alquiler por horas, turno de mañana o tarde, o día comercial completo. Las tarifas dependen de la sala, fecha y extras. Consulta el desglose real antes de enviar tu solicitud.' : 'Choose hourly rental, a morning or afternoon shift, or the full business day. Prices depend on room, date and extras. Review the live breakdown before submitting your request.' ) . '<!-- wp:ludora/booking /-->' ),
					'gallery'            => array( $es ? 'Galería' : 'Gallery', self::image( $photos[0] ) . self::image( $photos[1] ) . self::paragraph( $es ? 'Añade aquí fotografías autorizadas de tus salas. Esta demo no representa un local real.' : 'Add authorized photographs of your rooms here. This demonstration does not represent a real venue.' ) ),
					'faq'                => array( $es ? 'Preguntas frecuentes' : 'Frequently asked questions', self::faq( $es ) ),
					'contact'            => array( $es ? 'Contacto' : 'Contact', self::paragraph( $es ? 'Consulta disponibilidad en el formulario de reservas o contacta con el negocio mediante los datos publicados. Los datos de esta demo son ficticios.' : 'Check dates in the booking form or contact the venue using the published business details. This demo uses fictional details.' ) . '<!-- wp:ludora/business /-->' ),
					'journal'            => array( $es ? 'Ideas para celebrar' : 'Celebration journal', '' ),
					'confirmation'       => array( $es ? 'Estado de tu reserva' : 'Your booking status', self::paragraph( $es ? 'Usa el enlace privado que aparece al enviar tu solicitud. Una solicitud recibida no implica pago ni confirmación.' : 'Use the private link shown when you submit your request. A received request does not imply payment or confirmation.' ) . '<!-- wp:ludora/booking /-->' ),
					'booking-conditions' => array( $es ? 'Condiciones de reserva y cancelación — borrador' : 'Booking and cancellation conditions — draft', self::paragraph( $es ? 'BORRADOR EDITABLE: el propietario debe revisar y publicar las condiciones antes de abrir reservas. Las solicitudes manuales pendientes vencen según la configuración (demo: 48 horas). Las retenciones de pago vencen en 15 minutos por defecto. Una solicitud no es una confirmación. La cancelación de la reserva no implica reembolso automático. Define plazos, importes y normas reales del negocio.' : 'EDITABLE DRAFT: the owner must review and publish conditions before opening bookings. Pending manual requests expire according to settings (demo: 48 hours). Checkout holds expire after 15 minutes by default. A request is not confirmation. Cancelling a reservation does not automatically refund payment. Define actual venue deadlines, amounts and rules.' ) ),
					'privacy'            => array( $es ? 'Privacidad — borrador' : 'Privacy — draft', self::paragraph( $es ? 'BORRADOR EDITABLE: se recogen nombre, email, teléfono opcional y recuento de asistentes del adulto responsable. No se crean perfiles de menores. Configura responsable, conservación y derechos. WooCommerce conserva sus propios registros de pago. Solicita revisión jurídica para tu actividad.' : 'EDITABLE DRAFT: collect the responsible adult’s name, email, optional phone and attendance counts. No child profiles are created. Configure controller details, retention and rights. WooCommerce maintains its own payment records. Obtain legal review for your actual business.' ) ),
					'cookies'            => array( $es ? 'Cookies — borrador' : 'Cookies — draft', self::paragraph( $es ? 'BORRADOR EDITABLE: el formulario utiliza una cookie de sesión privada de una hora para proteger las solicitudes. WooCommerce añade sus cookies de carrito y pago cuando está activo. No se incluye analítica ni telemetría. Revisa cualquier servicio añadido por el propietario.' : 'EDITABLE DRAFT: the booking form uses a private one-hour session cookie to protect requests. WooCommerce adds cart and checkout cookies when active. No analytics or telemetry are included. Review any services subsequently added by the owner.' ) ),
				);
				foreach ( $pages as $slug => $page ) {
					$legal        = in_array( $slug, array( 'booking-conditions', 'privacy', 'cookies' ), true );
					$ids[ $slug ] = self::item( 'page-' . $slug, $lang, 'page', $page[0], $slug, $page[1], $report, null, $legal ? 'draft' : 'publish' ); }
				foreach ( array( 'play-park', 'celebration-room', 'birthday-experiences' ) as $i => $home ) {
						$title    = ( $es ? array( 'Jugar, reír y celebrar', 'Tu espacio para celebrar', 'Un cumpleaños lleno de experiencias' ) : array( 'Play, laugh and celebrate', 'Your space to celebrate', 'A birthday full of experiences' ) )[ $i ];
						$content  = self::home( $title, $i, $es, $photos[ $i % 2 ], $photos[ ( $i + 1 ) % 2 ] );
					$ids[ $home ] = self::item( 'home-' . $home, $lang, 'page', $title, $home, $content, $report );
				}
				self::item( 'post-welcome', $lang, 'post', $es ? 'Cómo preparar una fiesta sin prisas' : 'Planning a party without the rush', 'party-planning-' . $lang, self::paragraph( $es ? 'Contenido ficticio de demostración. Elige una sala adecuada al aforo, revisa el horario y consulta los extras antes de reservar.' : 'Fictional demonstration content. Choose a room with suitable capacity, review the schedule and check your extras before booking.' ), $report );
				if ( $set_home ) {
					update_option( 'show_on_front', 'page' );
					update_option( 'page_on_front', $ids['play-park'] );
					update_option( 'page_for_posts', $ids['journal'] );
					$report[] = 'Reading settings updated by explicit selection.'; }
				$nav = get_posts(
					array(
						'post_type'   => 'wp_navigation',
						'post_status' => 'any',
						'numberposts' => 1,
					)
				);
				if ( ! $nav ) {
					$links = '';
					foreach ( array( 'rooms', 'pricing', 'packages', 'gallery', 'about', 'contact' ) as $slug ) {
						$links .= '<!-- wp:navigation-link ' . wp_json_encode(
							array(
								'label' => get_the_title( $ids[ $slug ] ),
								'type'  => 'page',
								'id'    => $ids[ $slug ],
								'url'   => get_permalink( $ids[ $slug ] ),
								'kind'  => 'post-type',
							)
						) . ' /-->'; }
					wp_insert_post(
						array(
							'post_type'    => 'wp_navigation',
							'post_status'  => 'publish',
							'post_title'   => $es ? 'Navegación de demostración' : 'Demo navigation',
							'post_content' => $links,
							'meta_input'   => array( '_ludora_demo_key' => $lang . ':navigation' ),
						)
					);
					$report[] = 'Created editable native navigation.';
				} else {
					$report[] = 'Kept existing navigation.'; }
				$settings = Settings::get();
				foreach ( array(
					'business_email' => 'hello@example.test',
					'phone'          => '+34 000 000 000',
					'address'        => $es ? 'Calle de Ejemplo 12, Madrid (negocio ficticio)' : '12 Example Street, Madrid (fictional venue)',
					'hours'          => $es ? 'Lunes a domingo, 10:00–20:00 (demo)' : 'Monday–Sunday, 10:00–20:00 (demo)',
				) as $setting => $value ) {
					if ( '' === $settings[ $setting ] ) {
						$settings[ $setting ] = $value;
						$report[]             = 'Filled empty demo setting: ' . $setting; }
				}
				update_option( 'ludora_settings', $settings );
				update_option( 'ludora_demo_report', $report, false );
				flush_rewrite_rules();
				return $report;
			}
		);
	}
	/**
	 * Deduplicate an imported entity by its locale and stable source key.
	 *
	 * @param string     $key Stable demo entity key.
	 * @param string     $lang Demo language.
	 * @param string     $type Post type.
	 * @param string     $title Editable title.
	 * @param string     $slug Requested path.
	 * @param string     $content Native block markup.
	 * @param array      $report Import report updated by reference.
	 * @param array|null $config Optional catalogue configuration.
	 * @param string     $status Initial publication state.
	 * @param int        $thumbnail Initial featured image; existing editorial choices are preserved.
	 * @return int Saved post ID.
	 * @throws \RuntimeException When WordPress cannot insert the item.
	 */
	private static function item( string $key, string $lang, string $type, string $title, string $slug, string $content, array &$report, ?array $config = null, string $status = 'publish', int $thumbnail = 0 ): int {
		$found = get_posts(
			array(
				'post_type'   => $type,
				'post_status' => 'any',
				'numberposts' => 1,
				// Import-only stable keys deliberately use metadata lookups, never an availability query.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'    => '_ludora_demo_key',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'  => $lang . ':' . $key,
			)
		);
		if ( $found ) {
			$report[] = 'Skipped existing: ' . $title;
			return $found[0]->ID; }
		if ( get_page_by_path( $slug, OBJECT, $type ) ) {
			$slug .= '-demo-' . $lang; }
		$id = wp_insert_post(
			array(
				'post_type'    => $type,
				'post_status'  => $status,
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $content,
				'post_excerpt' => wp_trim_words( wp_strip_all_tags( $content ), 24 ),
				'meta_input'   => array_merge( array( '_ludora_demo_key' => $lang . ':' . $key ), str_starts_with( $key, 'home-' ) ? array( '_wp_page_template' => 'landing' ) : array() ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( esc_html( $id->get_error_message() ) ); }
		if ( $thumbnail ) {
			set_post_thumbnail( $id, $thumbnail );
		}
		if ( $config ) {
			update_post_meta( $id, '_ludora_config', $config );
		} $report[] = 'Created: ' . $title;
		return $id;
	}
	/**
	 * Import a bundled licensed photograph once and retain its attribution.
	 *
	 * @param string $file Bundled asset basename.
	 * @param string $lang Alternative-text language.
	 * @param array  $report Import report updated by reference.
	 * @return int Attachment ID.
	 * @throws \RuntimeException When the image cannot be imported.
	 */
	private static function photo( string $file, string $lang, array &$report ): int {
		$existing = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'numberposts' => 1,
				// Import-only stable keys deliberately use metadata lookups, never an availability query.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'    => '_ludora_demo_photo',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'  => $file,
			)
		);
		if ( $existing ) {
			return $existing[0]->ID; }
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = wp_tempnam( $file );
		if ( ! $tmp || ! copy( dirname( LUDORA_BOOKING_FILE ) . '/demo/assets/' . $file, $tmp ) ) {
			throw new \RuntimeException( 'Unable to prepare demo image.' ); }
		$id = media_handle_sideload(
			array(
				'name'     => $file,
				'tmp_name' => $tmp,
			),
			0
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			throw new \RuntimeException( esc_html( $id->get_error_message() ) ); }
		$credit = 'ball-pit.jpg' === $file ? 'Ball pit with playground slide — Rachmaninoff, CC BY-SA 4.0, Wikimedia Commons; resized.' : 'Indoor playground NL — Ziko, CC BY-SA 4.0, Wikimedia Commons; resized.';
		wp_update_post(
			array(
				'ID'           => $id,
				'post_excerpt' => $credit,
				'post_content' => $credit . ' https://creativecommons.org/licenses/by-sa/4.0/',
			)
		);
		update_post_meta( $id, '_wp_attachment_image_alt', 'es' === $lang ? 'Instalaciones de juego de ejemplo; no representan un negocio real de esta demo.' : 'Sample play facilities; not a real venue represented by this demo.' );
		update_post_meta( $id, '_ludora_demo_photo', $file );
		$report[] = 'Created licensed demo image: ' . $file;
		return $id;
	}
	/**
	 * Serialize canonical editable core/image markup with a credit caption.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	private static function image( int $id ): string {
		if ( ! $id ) {
			return ''; }
		$caption = wp_get_attachment_caption( $id );
		$source  = wp_get_attachment_image_url( $id, 'large' );
		$alt     = get_post_meta( $id, '_wp_attachment_image_alt', true );
		return '<!-- wp:image {"id":' . $id . ',"sizeSlug":"large","linkDestination":"none"} --><figure class="wp-block-image size-large"><img src="' . esc_url( $source ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $id . '"/><figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption></figure><!-- /wp:image -->';
	}
	/**
	 * Serialize an escaped editable paragraph.
	 *
	 * @param string $text Demo paragraph text.
	 * @return string
	 */
	private static function paragraph( string $text ): string {
		return '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->'; }
	/**
	 * Build localized demonstration questions with native details blocks.
	 *
	 * @param bool $es Use Spanish content.
	 * @return string
	 */
	private static function faq( bool $es ): string {
		$items = $es ? array(
			'¿La sala se comparte?'     => 'Cada reserva alquila una sala en exclusiva durante el intervalo contratado.',
			'¿Cuándo queda confirmada?' => 'Una solicitud manual requiere confirmación del negocio. El estado de pago se muestra por separado.',
			'¿Qué es un día completo?'  => 'Es el horario comercial configurado, no necesariamente 24 horas.',
			'¿Puedo cancelar?'          => 'Contacta con el negocio y consulta sus condiciones publicadas. Cancelación y reembolso son operaciones distintas.',
		) : array(
			'Is the room shared?'   => 'Each reservation exclusively rents one room for its booked interval.',
			'When is it confirmed?' => 'Manual requests require venue confirmation. Payment status is displayed separately.',
			'What is a full day?'   => 'The configured business opening hours, which are not necessarily 24 hours.',
			'Can I cancel?'         => 'Contact the venue and review its published conditions. Cancellation and refund are separate operations.',
		);
		$html  = '';
		foreach ( $items as $question => $answer ) {
			$html .= '<!-- wp:details --><details class="wp-block-details"><summary>' . esc_html( $question ) . '</summary>' . self::paragraph( $answer ) . '</details><!-- /wp:details -->';
		} return $html;
	}
	/**
	 * Compose an editable homepage variation from shared native components.
	 *
	 * @param string $title Homepage heading.
	 * @param int    $variant One of three demo variants.
	 * @param bool   $es Use Spanish content.
	 * @param int    $photo Licensed attachment ID.
	 * @param int    $gallery_photo Secondary licensed gallery attachment ID.
	 * @return string
	 */
	private static function home( string $title, int $variant, bool $es, int $photo = 0, int $gallery_photo = 0 ): string {
		$descriptions = $es ? array( 'Salas privadas, juegos a lo grande y un lugar para compartir la merienda. Tú eliges la fecha; nosotros ponemos el espacio.', 'Un espacio luminoso con mesas, zona de juegos y todo lo necesario para reunir a quienes más quieres.', 'Combina tu sala con animación, decoración y una merienda para crear una celebración a tu medida.' ) : array( 'Private rooms, big adventures and a place to share a birthday snack. Choose your date; make the space yours.', 'A bright space with tables, play areas and everything you need to bring your favourite people together.', 'Combine your room with a party host, decorations and snacks to create your own celebration.' );
		$class        = array( '', ' is-style-celebration', ' is-style-birthday' )[ $variant ];
		$html         = '<!-- wp:group ' . wp_json_encode(
			array(
				'align'     => 'wide',
				'className' => 'ludora-hero' . $class,
				'layout'    => array(
					'type'        => 'constrained',
					'contentSize' => '1200px',
				),
			)
		) . ' --><div class="wp-block-group alignwide ludora-hero' . esc_attr( $class ) . '">';
		$html        .= '<!-- wp:columns {"verticalAlignment":"center"} --><div class="wp-block-columns are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center"} --><div class="wp-block-column is-vertically-aligned-center">';
		$html        .= self::paragraph( $es ? 'PEQUEÑAS PERSONAS. GRANDES MOMENTOS.' : 'LITTLE PEOPLE. BIG MOMENTS.' );
		$html        .= '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">' . esc_html( $title ) . '</h1><!-- /wp:heading -->' . self::paragraph( $descriptions[ $variant ] );
		$html        .= '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( home_url( '/booking/' ) ) . '">' . esc_html( $es ? 'Elegir fecha y sala' : 'Find your room and date' ) . '</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:column --><!-- wp:column {"verticalAlignment":"center"} --><div class="wp-block-column is-vertically-aligned-center">' . self::image( $photo ) . '</div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->';
		$html        .= '<!-- wp:group {"align":"wide","layout":{"type":"constrained","contentSize":"1200px"}} --><div class="wp-block-group alignwide"><!-- wp:ludora/search /--></div><!-- /wp:group -->';
		$sections     = array(
			array( $es ? 'Encuentra tu sala favorita' : 'Find your favourite room', '<!-- wp:ludora/rooms /-->' ),
			array( $es ? 'Un espacio para estar a gusto' : 'Room to feel at home', self::paragraph( $es ? 'Juegos, mesas para compartir la merienda y espacios privados. Consulta el equipamiento, aforo y normas de cada sala antes de reservar.' : 'Play areas, tables for birthday snacks and your own private space. Review each room’s facilities, capacity and house rules before booking.' ) . self::image( $photo ) ),
			array( $es ? 'Así de fácil' : 'Make it happen', self::paragraph( $es ? '1. Elige sala y fecha. 2. Revisa precio y extras. 3. Envía la solicitud o completa el pago si está habilitado. Consulta siempre el estado real de la reserva.' : '1. Choose your room and date. 2. Review price and extras. 3. Send a request or pay when enabled. Always check the actual reservation status.' ) ),
			array( $es ? 'Una fiesta a tu medida' : 'A celebration your way', '<!-- wp:ludora/packages /-->' ),
			array( $es ? 'Los pequeños detalles' : 'The little extras', '<!-- wp:ludora/extras /-->' ),
			array( $es ? 'Galería' : 'Gallery', self::image( $gallery_photo ? $gallery_photo : $photo ) . self::paragraph( $es ? 'Consulta todas las fotografías en la página de galería.' : 'See all photographs on the gallery page.' ) . '<!-- wp:paragraph --><p><a href="' . esc_url( home_url( '/gallery/' ) ) . '">' . esc_html( $es ? 'Ver galería' : 'View gallery' ) . '</a></p><!-- /wp:paragraph -->' ),
			array( $es ? 'Resuelve tus dudas' : 'Good to know', self::faq( $es ) ),
			array( $es ? 'Hablemos de tu fiesta' : 'Let’s plan your party', '<!-- wp:ludora/business /-->' ),
		);
		foreach ( $sections as $section ) {
			$html .= '<!-- wp:group {"align":"wide","layout":{"type":"constrained","contentSize":"1200px"}} --><div class="wp-block-group alignwide"><!-- wp:heading --><h2 class="wp-block-heading">' . esc_html( $section[0] ) . '</h2><!-- /wp:heading -->' . $section[1] . '</div><!-- /wp:group -->';
		}
		return $html;
	}
}
