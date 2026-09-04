<?php
/**
 * Cache del registro de esquema.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Schema;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\Cache\CacheManager;
use WpApi\Codeia\Core\Config;

/**
 * Guarda y recupera definiciones de recurso con clave versionada.
 *
 * La clave incorpora un hash del estado del entorno. Cuando ese hash cambia,
 * las entradas anteriores quedan huerfanas y expiran solas: no hace falta un
 * borrado por grupos, que es exactamente lo que el object cache de WordPress
 * no garantiza (wp_cache_flush_group no esta soportado por todos los
 * backends).
 */
final class SchemaCache {

	/**
	 * Gestor de cache.
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache;

	/**
	 * Configuracion, de la que sale el hash de entorno.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Hash de entorno resuelto una vez por peticion.
	 *
	 * @var string|null
	 */
	private ?string $env_hash = null;

	/**
	 * Construye la cache.
	 *
	 * @param CacheManager $cache  Gestor de cache.
	 * @param Config       $config Configuracion.
	 */
	public function __construct( CacheManager $cache, Config $config ) {
		$this->cache  = $cache;
		$this->config = $config;
	}

	/**
	 * Hash del estado del entorno.
	 *
	 * @return string
	 */
	public function env_hash(): string {
		if ( null === $this->env_hash ) {
			$this->env_hash = $this->config->env_hash();
		}

		return $this->env_hash;
	}

	/**
	 * Compone la clave de cache de un recurso.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	public function key_for( string $post_type ): string {
		return sprintf( '%s:%s', $this->env_hash(), $post_type );
	}

	/**
	 * Recupera una definicion cacheada.
	 *
	 * @param string $post_type Post type.
	 * @return ResourceDefinition|null
	 */
	public function get( string $post_type ): ?ResourceDefinition {
		$found = false;
		$data  = $this->cache->get( $this->key_for( $post_type ), CacheManager::GROUP_SCHEMA, $found );

		if ( ! $found || ! is_array( $data ) ) {
			return null;
		}

		return ResourceDefinition::from_array( $data );
	}

	/**
	 * Guarda una definicion.
	 *
	 * @param ResourceDefinition $definition Definicion.
	 * @return bool
	 */
	public function put( ResourceDefinition $definition ): bool {
		return $this->cache->set(
			$this->key_for( $definition->post_type ),
			$definition->to_array(),
			CacheManager::GROUP_SCHEMA
		);
	}

	/**
	 * Borra la entrada de un recurso.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function forget( string $post_type ): bool {
		return $this->cache->delete( $this->key_for( $post_type ), CacheManager::GROUP_SCHEMA );
	}

	/**
	 * Clave del muestreo de base de datos, que se cachea aparte.
	 *
	 * El muestreo es lo caro del rebuild. Separarlo permite reconstruir los
	 * niveles baratos sin volver a consultar wp_postmeta.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	public function sample_key_for( string $post_type ): string {
		return sprintf( 'sample:%s:%s', $this->env_hash(), $post_type );
	}

	/**
	 * Invalida el hash memorizado en esta peticion.
	 *
	 * Se llama tras un rebuild manual, cuando la configuracion acaba de
	 * cambiar y el hash calculado antes ya no vale.
	 *
	 * @return void
	 */
	public function reset_env_hash(): void {
		$this->env_hash = null;
		$this->cache->flush_memory( CacheManager::GROUP_SCHEMA );
	}
}
