<?php
/**
 * Deduplicacion de ficheros por hash.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Media;

defined( 'ABSPATH' ) || exit;

/**
 * Evita reescribir un fichero que ya existe.
 *
 * Ahorra espacio y ancho de banda en el caso real de reintentos de cliente
 * por timeout, donde el mismo fichero llega dos o tres veces.
 */
final class Deduplicator {

	/**
	 * Clave meta donde se guarda el hash del fichero.
	 */
	public const META_KEY = '_codeia_file_hash';

	/**
	 * Calcula el hash de un fichero.
	 *
	 * @param string $path Ruta del fichero.
	 * @return string
	 */
	public function hash( string $path ): string {
		$hash = hash_file( 'sha256', $path );

		return is_string( $hash ) ? $hash : '';
	}

	/**
	 * Busca un adjunto existente con el mismo hash.
	 *
	 * @param string $hash Hash del fichero.
	 * @return int ID del adjunto, o 0 si no existe.
	 */
	public function find( string $hash ): int {
		if ( '' === $hash ) {
			return 0;
		}

		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::META_KEY,
				'meta_value'     => $hash,
				'no_found_rows'  => true,
			)
		);

		return is_array( $found ) && array() !== $found ? (int) $found[0] : 0;
	}

	/**
	 * Registra el hash de un adjunto.
	 *
	 * @param int    $attachment_id ID del adjunto.
	 * @param string $hash          Hash.
	 * @return void
	 */
	public function remember( int $attachment_id, string $hash ): void {
		if ( '' !== $hash ) {
			update_post_meta( $attachment_id, self::META_KEY, $hash );
		}
	}
}
