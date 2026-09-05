<?php
/**
 * Cache del documento OpenAPI.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\OpenApi;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\Cache\CacheManager;
use WpApi\Codeia\Core\Cache\StampedeLock;
use WpApi\Codeia\Permissions\FieldVisibility;
use WpApi\Codeia\Schema\SchemaCache;

/**
 * Guarda el documento por ambito de permisos.
 *
 * Generarlo recorre todos los recursos, campos y operaciones: decenas o
 * cientos de milisegundos, demasiado para cada peticion a /docs.
 *
 * La clave incluye el hash del conjunto de campos visibles, de modo que un
 * anonimo y un editor no comparten entrada. Sin esa distincion, la respuesta
 * cacheada de un rol se serviria a otro.
 */
final class SpecCache {

	/**
	 * Gestor de cache.
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache;

	/**
	 * Cerrojo contra estampidas.
	 *
	 * @var StampedeLock
	 */
	private StampedeLock $lock;

	/**
	 * Cache de esquema, de la que sale la version de entorno.
	 *
	 * @var SchemaCache
	 */
	private SchemaCache $schema;

	/**
	 * Visibilidad por campo.
	 *
	 * @var FieldVisibility
	 */
	private FieldVisibility $visibility;

	/**
	 * Construye la cache.
	 *
	 * @param CacheManager    $cache      Gestor de cache.
	 * @param StampedeLock    $lock       Cerrojo.
	 * @param SchemaCache     $schema     Cache de esquema.
	 * @param FieldVisibility $visibility Visibilidad.
	 */
	public function __construct(
		CacheManager $cache,
		StampedeLock $lock,
		SchemaCache $schema,
		FieldVisibility $visibility
	) {
		$this->cache      = $cache;
		$this->lock       = $lock;
		$this->schema     = $schema;
		$this->visibility = $visibility;
	}

	/**
	 * Devuelve el documento cacheado o lo genera.
	 *
	 * @param int      $user_id  Usuario.
	 * @param string[] $fields   Campos candidatos, para el hash de ambito.
	 * @param string   $resource Recurso de referencia del ambito.
	 * @param callable $producer Generador del documento.
	 * @return array<string, mixed>
	 */
	public function remember( int $user_id, array $fields, string $resource, callable $producer ): array {
		$key = $this->key_for( $user_id, $fields, $resource );

		$value = $this->lock->remember(
			$key,
			CacheManager::GROUP_OPENAPI,
			$producer,
			$this->cache->ttl_for( CacheManager::GROUP_OPENAPI )
		);

		return is_array( $value ) ? $value : $producer();
	}

	/**
	 * Compone la clave del documento.
	 *
	 * @param int      $user_id  Usuario.
	 * @param string[] $fields   Campos candidatos.
	 * @param string   $resource Recurso.
	 * @return string
	 */
	public function key_for( int $user_id, array $fields, string $resource ): string {
		return sprintf(
			'%s:%s',
			$this->schema->env_hash(),
			$this->visibility->fields_hash( $fields, $user_id, $resource )
		);
	}

	/**
	 * Borra la entrada de un ambito.
	 *
	 * @param string $key Clave.
	 * @return bool
	 */
	public function forget( string $key ): bool {
		return $this->cache->delete( $key, CacheManager::GROUP_OPENAPI );
	}
}
