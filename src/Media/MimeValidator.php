<?php
/**
 * Validacion de ficheros subidos.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Media;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Valida un fichero por su CONTENIDO, nunca por lo que declare el cliente.
 *
 * Tres datos vienen del cliente y ninguno es fiable: el type de $_FILES lo
 * envia el navegador, la extension del nombre es trivial de falsificar
 * (payload.php.jpg) y la cabecera Content-Type igual.
 *
 * La unica fuente fiable es el contenido del fichero en disco.
 */
final class MimeValidator {

	/**
	 * Formatos admitidos por defecto.
	 *
	 * SVG queda FUERA y no se ofrece como opcion: es XML que admite <script>
	 * y referencias externas, asi que subirlo y servirlo desde el dominio del
	 * sitio es XSS almacenado con acceso a la sesion del administrador.
	 */
	public const DEFAULT_ALLOWED = array(
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'gif'      => 'image/gif',
		'webp'     => 'image/webp',
		'avif'     => 'image/avif',
	);

	/**
	 * Dimension maxima por lado, en pixeles.
	 */
	public const MAX_DIMENSION = 8000;

	/**
	 * Formatos admitidos.
	 *
	 * @var array<string, string>
	 */
	private array $allowed;

	/**
	 * Construye el validador.
	 *
	 * @param array<string, string>|null $allowed Formatos admitidos.
	 */
	public function __construct( ?array $allowed = null ) {
		$this->allowed = $allowed ?? self::DEFAULT_ALLOWED;
	}

	/**
	 * Valida un fichero subido.
	 *
	 * @param string $tmp_path  Ruta temporal del fichero.
	 * @param string $file_name Nombre declarado por el cliente.
	 * @param int    $max_bytes Tamano maximo admitido.
	 * @param int    $max_side  Dimension maxima por lado.
	 * @return true|WP_Error
	 */
	public function validate( string $tmp_path, string $file_name, int $max_bytes, int $max_side = self::MAX_DIMENSION ) {
		if ( ! file_exists( $tmp_path ) ) {
			return new WP_Error(
				'codeia_no_file',
				__( 'No se ha recibido ningun fichero.', 'wp-api-codeia' ),
				array( 'status' => 400 )
			);
		}

		$size = (int) filesize( $tmp_path );

		if ( $size > $max_bytes ) {
			return new WP_Error(
				'codeia_file_too_large',
				__( 'El fichero supera el tamano maximo permitido.', 'wp-api-codeia' ),
				array(
					'status' => 413,
					'limit'  => $max_bytes,
					'size'   => $size,
				)
			);
		}

		$checked = wp_check_filetype_and_ext( $tmp_path, $file_name, $this->allowed );

		if ( empty( $checked['type'] ) ) {
			return new WP_Error(
				'codeia_mime_not_allowed',
				__( 'El formato del fichero no esta permitido.', 'wp-api-codeia' ),
				array( 'status' => 415 )
			);
		}

		$real = $this->detect( $tmp_path );

		if ( '' === $real || $real !== $checked['type'] ) {
			return new WP_Error(
				'codeia_mime_mismatch',
				__( 'El contenido del fichero no coincide con su extension.', 'wp-api-codeia' ),
				array( 'status' => 415 )
			);
		}

		if ( ! in_array( $real, array_values( $this->allowed ), true ) ) {
			return new WP_Error(
				'codeia_mime_not_allowed',
				__( 'El formato del fichero no esta permitido.', 'wp-api-codeia' ),
				array( 'status' => 415 )
			);
		}

		return $this->validate_dimensions( $tmp_path, $max_side );
	}

	/**
	 * Comprueba que la imagen es procesable y no desmesurada.
	 *
	 * El limite de dimensiones importa aparte del de tamano: un PNG de
	 * 30000x30000 puede pesar poco comprimido y necesitar gigabytes al
	 * descomprimirse para generar los tamanos derivados. Es una bomba de
	 * descompresion, y se detecta ANTES de procesar.
	 *
	 * @param string $tmp_path Ruta temporal.
	 * @param int    $max_side Dimension maxima.
	 * @return true|WP_Error
	 */
	private function validate_dimensions( string $tmp_path, int $max_side ) {
		$info = @getimagesize( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $info ) {
			return new WP_Error(
				'codeia_invalid_image',
				__( 'El fichero no es una imagen procesable.', 'wp-api-codeia' ),
				array( 'status' => 415 )
			);
		}

		if ( (int) $info[0] > $max_side || (int) $info[1] > $max_side ) {
			return new WP_Error(
				'codeia_dimensions_exceeded',
				__( 'La imagen supera las dimensiones maximas permitidas.', 'wp-api-codeia' ),
				array(
					'status' => 413,
					'limit'  => $max_side,
					'width'  => (int) $info[0],
					'height' => (int) $info[1],
				)
			);
		}

		return true;
	}

	/**
	 * Detecta el MIME real leyendo el contenido.
	 *
	 * @param string $path Ruta del fichero.
	 * @return string Cadena vacia si no se puede determinar.
	 */
	public function detect( string $path ): string {
		if ( ! function_exists( 'finfo_open' ) ) {
			$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			return is_array( $info ) && isset( $info['mime'] ) ? (string) $info['mime'] : '';
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );

		if ( false === $finfo ) {
			return '';
		}

		$mime = finfo_file( $finfo, $path );
		finfo_close( $finfo );

		return is_string( $mime ) ? $mime : '';
	}

	/**
	 * Formatos admitidos.
	 *
	 * @return array<string, string>
	 */
	public function allowed(): array {
		return $this->allowed;
	}
}
