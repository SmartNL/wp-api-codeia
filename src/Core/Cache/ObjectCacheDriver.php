<?php
/**
 * Driver sobre el object cache de WordPress.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Core\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Almacen sobre wp_cache_*.
 *
 * Solo se elige cuando hay un backend persistente (Redis, Memcached). Sin el,
 * el object cache de WordPress vive en memoria y no sobrevive a la peticion,
 * con lo que no aporta nada sobre MemoryDriver.
 */
final class ObjectCacheDriver implements CacheInterface {

	/**
	 * {@inheritDoc}
	 *
	 * @param string    $key   Clave.
	 * @param string    $group Grupo.
	 * @param bool|null $found Recibe si la clave existia.
	 * @return mixed
	 */
	public function get( string $key, string $group, ?bool &$found = null ): mixed {
		$hit   = false;
		$value = wp_cache_get( $key, $group, false, $hit );
		$found = (bool) $hit;

		return $found ? $value : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key   Clave.
	 * @param mixed  $value Valor.
	 * @param string $group Grupo.
	 * @param int    $ttl   Segundos de vida.
	 * @return bool
	 */
	public function set( string $key, mixed $value, string $group, int $ttl = 0 ): bool {
		return (bool) wp_cache_set( $key, $value, $group, $ttl );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key   Clave.
	 * @param string $group Grupo.
	 * @return bool
	 */
	public function delete( string $key, string $group ): bool {
		return (bool) wp_cache_delete( $key, $group );
	}

	/**
	 * Reserva una clave de forma atomica.
	 *
	 * La operacion solo tiene exito si la clave no existe todavia, que es lo
	 * que hace a wp_cache_add() util como cerrojo contra estampidas de cache.
	 *
	 * @param string $key   Clave.
	 * @param mixed  $value Valor.
	 * @param string $group Grupo.
	 * @param int    $ttl   Segundos de vida.
	 * @return bool True si se reservo, false si ya existia.
	 */
	public function add( string $key, mixed $value, string $group, int $ttl = 0 ): bool {
		return (bool) wp_cache_add( $key, $value, $group, $ttl );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_persistent(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name(): string {
		return 'object_cache';
	}
}
