<?php
/**
 * Limpieza de metadatos EXIF.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Elimina la geolocalizacion de las fotografias subidas.
 *
 * Las fotos de movil incluyen coordenadas GPS. Esto conecta directamente con
 * la matriz de permisos: alli se ocultan latitude y longitude al publico por
 * ser un dato explotable, y publicar la foto del inmueble con su GPS en el
 * EXIF anularia esa proteccion por completo.
 *
 * Por defecto se elimina solo el GPS, conservando orientacion y perfil de
 * color, que la imagen necesita para verse correctamente.
 */
final class ExifCleaner {

	public const MODE_KEEP_ALL  = 'keep_all';
	public const MODE_STRIP_GPS = 'strip_gps';
	public const MODE_STRIP_ALL = 'strip_all';

	/**
	 * Modo de limpieza.
	 *
	 * @var string
	 */
	private string $mode;

	/**
	 * Construye el limpiador.
	 *
	 * @param string $mode Modo de limpieza.
	 */
	public function __construct( string $mode = self::MODE_STRIP_GPS ) {
		$this->mode = in_array(
			$mode,
			array( self::MODE_KEEP_ALL, self::MODE_STRIP_GPS, self::MODE_STRIP_ALL ),
			true
		) ? $mode : self::MODE_STRIP_GPS;
	}

	/**
	 * Indica si hay que limpiar algo.
	 *
	 * @return bool
	 */
	public function should_clean(): bool {
		return self::MODE_KEEP_ALL !== $this->mode;
	}

	/**
	 * Elimina las claves de geolocalizacion de los metadatos.
	 *
	 * Actua sobre el array de metadatos que WordPress guarda, que es lo que
	 * el plugin controla; el EXIF del binario lo pierde el reprocesado que
	 * hace wp_generate_attachment_metadata al crear los tamanos derivados.
	 *
	 * @param array<string, mixed> $metadata Metadatos del adjunto.
	 * @return array<string, mixed>
	 */
	public function clean( array $metadata ): array {
		if ( ! $this->should_clean() || ! isset( $metadata['image_meta'] ) ) {
			return $metadata;
		}

		if ( ! is_array( $metadata['image_meta'] ) ) {
			return $metadata;
		}

		if ( self::MODE_STRIP_ALL === $this->mode ) {
			$metadata['image_meta'] = array();

			return $metadata;
		}

		foreach ( array( 'latitude', 'longitude', 'gps_latitude', 'gps_longitude', 'location' ) as $key ) {
			unset( $metadata['image_meta'][ $key ] );
		}

		return $metadata;
	}

	/**
	 * Modo activo.
	 *
	 * @return string
	 */
	public function mode(): string {
		return $this->mode;
	}
}
