<?php
/**
 * Validacion de ficheros subidos.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Integration\Media;

use WP_UnitTestCase;
use WpApi\Codeia\Media\Deduplicator;
use WpApi\Codeia\Media\ExifCleaner;
use WpApi\Codeia\Media\MimeValidator;

/**
 * @covers \WpApi\Codeia\Media\MimeValidator
 * @covers \WpApi\Codeia\Media\Deduplicator
 * @covers \WpApi\Codeia\Media\ExifCleaner
 */
final class UploadValidationTest extends WP_UnitTestCase {

	private MimeValidator $validator;

	/**
	 * Ficheros temporales creados, para limpiarlos.
	 *
	 * @var string[]
	 */
	private array $temp = array();

	public function set_up(): void {
		parent::set_up();
		$this->validator = new MimeValidator();
	}

	public function tear_down(): void {
		foreach ( $this->temp as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}

		$this->temp = array();
		parent::tear_down();
	}

	/**
	 * Escribe un fichero temporal con el contenido dado.
	 *
	 * @param string $content   Contenido binario.
	 * @param string $extension Extension.
	 * @return string Ruta del fichero.
	 */
	private function temp_file( string $content, string $extension ): string {
		$path = tempnam( sys_get_temp_dir(), 'codeia' ) . '.' . $extension;
		file_put_contents( $path, $content );
		$this->temp[] = $path;

		return $path;
	}

	/**
	 * Genera un PNG real de las dimensiones indicadas.
	 *
	 * @param int $width  Ancho.
	 * @param int $height Alto.
	 * @return string
	 */
	private function png( int $width = 10, int $height = 10 ): string {
		$image = imagecreatetruecolor( $width, $height );
		ob_start();
		imagepng( $image );
		$data = (string) ob_get_clean();
		imagedestroy( $image );

		return $data;
	}

	public function test_acepta_un_png_valido(): void {
		$path = $this->temp_file( $this->png(), 'png' );

		$this->assertTrue( $this->validator->validate( $path, 'foto.png', 5 * MB_IN_BYTES ) );
	}

	/**
	 * El ataque clasico: PHP renombrado a .jpg. La extension miente, el
	 * contenido no.
	 */
	public function test_rechaza_php_disfrazado_de_imagen(): void {
		$path  = $this->temp_file( '<?php echo "ejecutable"; ?>', 'jpg' );
		$error = $this->validator->validate( $path, 'payload.jpg', 5 * MB_IN_BYTES );

		$this->assertWPError( $error );
		$this->assertContains(
			$error->get_error_code(),
			array( 'codeia_mime_mismatch', 'codeia_mime_not_allowed' )
		);
	}

	public function test_rechaza_doble_extension(): void {
		$path  = $this->temp_file( '<?php phpinfo(); ?>', 'jpg' );
		$error = $this->validator->validate( $path, 'payload.php.jpg', 5 * MB_IN_BYTES );

		$this->assertWPError( $error );
	}

	/**
	 * SVG es XML que admite script: servirlo desde el dominio del sitio es
	 * XSS almacenado con acceso a la sesion del administrador.
	 */
	public function test_rechaza_svg(): void {
		$svg   = '<svg xmlns="http://www.w3.org/2000/svg"></svg>';
		$path  = $this->temp_file( $svg, 'svg' );
		$error = $this->validator->validate( $path, 'imagen.svg', 5 * MB_IN_BYTES );

		$this->assertWPError( $error );
	}

	public function test_svg_no_esta_en_la_lista_blanca(): void {
		$this->assertNotContains( 'image/svg+xml', array_values( $this->validator->allowed() ) );
	}

	public function test_rechaza_un_fichero_demasiado_grande(): void {
		$path  = $this->temp_file( $this->png(), 'png' );
		$error = $this->validator->validate( $path, 'foto.png', 10 );

		$this->assertWPError( $error );
		$this->assertSame( 'codeia_file_too_large', $error->get_error_code() );
		$this->assertSame( 413, $error->get_error_data()['status'] );
	}

	/**
	 * Un PNG enorme puede pesar poco comprimido y agotar la memoria al
	 * generar los tamanos derivados. Se detecta ANTES de procesar.
	 */
	public function test_rechaza_dimensiones_desmesuradas(): void {
		$path  = $this->temp_file( $this->png( 100, 100 ), 'png' );
		$error = $this->validator->validate( $path, 'grande.png', 5 * MB_IN_BYTES, 50 );

		$this->assertWPError( $error );
		$this->assertSame( 'codeia_dimensions_exceeded', $error->get_error_code() );
	}

	public function test_rechaza_un_fichero_que_no_es_imagen(): void {
		$path  = $this->temp_file( 'solo texto plano', 'txt' );
		$error = $this->validator->validate( $path, 'notas.txt', 5 * MB_IN_BYTES );

		$this->assertWPError( $error );
	}

	public function test_detecta_el_mime_real_por_contenido(): void {
		$path = $this->temp_file( $this->png(), 'png' );

		$this->assertSame( 'image/png', $this->validator->detect( $path ) );
	}

	public function test_el_mime_real_ignora_la_extension(): void {
		$path = $this->temp_file( $this->png(), 'jpg' );

		$this->assertSame(
			'image/png',
			$this->validator->detect( $path ),
			'El contenido manda sobre el nombre.'
		);
	}

	public function test_el_deduplicador_reconoce_contenido_identico(): void {
		$dedup = new Deduplicator();
		$data  = $this->png();

		$uno = $this->temp_file( $data, 'png' );
		$dos = $this->temp_file( $data, 'png' );

		$this->assertSame( $dedup->hash( $uno ), $dedup->hash( $dos ) );
	}

	public function test_el_deduplicador_distingue_contenido_distinto(): void {
		$dedup = new Deduplicator();

		$uno = $this->temp_file( $this->png( 10, 10 ), 'png' );
		$dos = $this->temp_file( $this->png( 20, 20 ), 'png' );

		$this->assertNotSame( $dedup->hash( $uno ), $dedup->hash( $dos ) );
	}

	/**
	 * Publicar la foto del inmueble con su GPS anularia la ocultacion de
	 * latitude y longitude que hace la matriz de permisos.
	 */
	public function test_el_limpiador_elimina_la_geolocalizacion(): void {
		$cleaner = new ExifCleaner( ExifCleaner::MODE_STRIP_GPS );

		$limpio = $cleaner->clean(
			array(
				'image_meta' => array(
					'latitude'    => '40.4265',
					'longitude'   => '-3.7038',
					'orientation' => 1,
					'camera'      => 'Pixel',
				),
			)
		);

		$this->assertArrayNotHasKey( 'latitude', $limpio['image_meta'] );
		$this->assertArrayNotHasKey( 'longitude', $limpio['image_meta'] );
		$this->assertSame( 1, $limpio['image_meta']['orientation'], 'La orientacion se conserva.' );
	}

	public function test_el_modo_keep_all_no_toca_nada(): void {
		$cleaner = new ExifCleaner( ExifCleaner::MODE_KEEP_ALL );
		$meta    = array( 'image_meta' => array( 'latitude' => '40.4' ) );

		$this->assertSame( $meta, $cleaner->clean( $meta ) );
	}

	public function test_el_modo_strip_all_vacia_los_metadatos(): void {
		$cleaner = new ExifCleaner( ExifCleaner::MODE_STRIP_ALL );

		$limpio = $cleaner->clean( array( 'image_meta' => array( 'camera' => 'Pixel' ) ) );

		$this->assertSame( array(), $limpio['image_meta'] );
	}

	public function test_un_modo_desconocido_cae_al_seguro(): void {
		$this->assertSame( ExifCleaner::MODE_STRIP_GPS, ( new ExifCleaner( 'inventado' ) )->mode() );
	}
}
