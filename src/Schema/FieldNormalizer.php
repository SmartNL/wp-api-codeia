<?php
/**
 * Normalizacion de nombres de campo.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Traduce claves de almacenamiento a nombres publicos y resuelve colisiones.
 *
 * Las claves meta reales rara vez sirven como nombres de API: exponer
 * _property_price filtra detalles de implementacion. El mapa es bidireccional
 * porque el motor de consultas necesita volver de "price" a la clave real.
 */
final class FieldNormalizer {

	/**
	 * Nombres reservados por la representacion nativa de un post.
	 *
	 * Un campo meta que normalice a uno de estos NO puede quedarse con el
	 * nombre: los campos nativos siempre ganan.
	 */
	public const RESERVED = array(
		'id',
		'date',
		'date_gmt',
		'guid',
		'modified',
		'modified_gmt',
		'slug',
		'status',
		'type',
		'link',
		'title',
		'content',
		'excerpt',
		'author',
		'featured_media',
		'comment_status',
		'ping_status',
		'sticky',
		'template',
		'format',
		'meta',
		'password',
		'parent',
		'menu_order',
		'permalink_template',
		'generated_slug',
	);

	/**
	 * Colisiones detectadas en la ultima normalizacion.
	 *
	 * @var array<string, string>
	 */
	private array $collisions = array();

	/**
	 * Normaliza una clave de almacenamiento a nombre publico.
	 *
	 * @param string $storage_key Clave meta.
	 * @param string $post_type   Post type, para retirar su prefijo.
	 * @return string
	 */
	public function normalize( string $storage_key, string $post_type = '' ): string {
		$name = ltrim( $storage_key, '_' );

		if ( '' !== $post_type ) {
			$prefix = $post_type . '_';

			if ( str_starts_with( $name, $prefix ) && strlen( $name ) > strlen( $prefix ) ) {
				$name = substr( $name, strlen( $prefix ) );
			}
		}

		$name = strtolower( $name );
		$name = preg_replace( '/[^a-z0-9_]+/', '_', $name ) ?? $name;
		$name = trim( (string) $name, '_' );

		return '' === $name ? strtolower( trim( $storage_key, '_' ) ) : $name;
	}

	/**
	 * Normaliza un conjunto de claves resolviendo colisiones.
	 *
	 * Cuando dos claves distintas producen el mismo nombre, o cuando el
	 * nombre choca con un campo nativo, se conserva la clave completa como
	 * nombre publico y se registra el conflicto. Resolverlo en silencio
	 * dejaria un campo inaccesible sin explicacion.
	 *
	 * @param string[] $storage_keys Claves de almacenamiento.
	 * @param string   $post_type    Post type.
	 * @return array<string, string> Mapa clave de almacenamiento => nombre publico.
	 */
	public function normalize_all( array $storage_keys, string $post_type = '' ): array {
		$this->collisions = array();

		$map   = array();
		$taken = array();

		foreach ( self::RESERVED as $reserved ) {
			$taken[ $reserved ] = '(campo nativo)';
		}

		foreach ( $storage_keys as $key ) {
			$name = $this->normalize( $key, $post_type );

			if ( isset( $taken[ $name ] ) ) {
				$this->collisions[ $key ] = sprintf(
					'el nombre "%s" ya lo ocupa %s',
					$name,
					$taken[ $name ]
				);

				// Se conserva la clave completa, saneada, como nombre publico.
				$name = $this->fallback_name( $key );
			}

			$taken[ $name ] = $key;
			$map[ $key ]    = $name;
		}

		return $map;
	}

	/**
	 * Nombre de reserva cuando el normalizado colisiona.
	 *
	 * @param string $storage_key Clave meta.
	 * @return string
	 */
	private function fallback_name( string $storage_key ): string {
		$name = strtolower( ltrim( $storage_key, '_' ) );
		$name = preg_replace( '/[^a-z0-9_]+/', '_', $name ) ?? $name;

		return trim( (string) $name, '_' );
	}

	/**
	 * Colisiones de la ultima normalizacion, para el panel de estado.
	 *
	 * @return array<string, string>
	 */
	public function collisions(): array {
		return $this->collisions;
	}

	/**
	 * Indica si un nombre esta reservado por los campos nativos.
	 *
	 * @param string $name Nombre publico.
	 * @return bool
	 */
	public static function is_reserved( string $name ): bool {
		return in_array( strtolower( $name ), self::RESERVED, true );
	}
}
