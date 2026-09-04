<?php
/**
 * Cerrojo contra estampidas de cache.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Core\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Evita que varias peticiones reconstruyan a la vez un valor caro.
 *
 * Cuando una clave cara expira con peticiones concurrentes, todas
 * reconstruyen a la vez. Con el esquema —que puede tardar segundos— eso basta
 * para tumbar el sitio.
 *
 * La reserva usa add() y no set(): add() solo tiene exito si la clave no
 * existe, y esa es la propiedad que lo hace atomico.
 */
final class StampedeLock {

	/**
	 * Grupo de los cerrojos.
	 */
	private const GROUP = 'codeia_locks';

	/**
	 * Duracion del cerrojo, en segundos.
	 */
	public const TTL = 30;

	/**
	 * Gestor de cache.
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache;

	/**
	 * Construye el cerrojo.
	 *
	 * @param CacheManager $cache Gestor de cache.
	 */
	public function __construct( CacheManager $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Intenta adquirir el cerrojo de una clave.
	 *
	 * @param string $key Clave protegida.
	 * @param int    $ttl Duracion del cerrojo.
	 * @return bool True si se obtuvo.
	 */
	public function acquire( string $key, int $ttl = self::TTL ): bool {
		$lock  = $this->lock_key( $key );
		$found = false;

		$this->cache->get( $lock, self::GROUP, $found );

		if ( $found ) {
			return false;
		}

		return $this->cache->set( $lock, 1, self::GROUP, $ttl );
	}

	/**
	 * Libera el cerrojo.
	 *
	 * @param string $key Clave protegida.
	 * @return bool
	 */
	public function release( string $key ): bool {
		return $this->cache->delete( $this->lock_key( $key ), self::GROUP );
	}

	/**
	 * Devuelve el valor cacheado o lo reconstruye bajo cerrojo.
	 *
	 * Quien no obtiene el cerrojo sirve la version anterior si la hay, en
	 * lugar de reconstruir en paralelo.
	 *
	 * @param string   $key      Clave.
	 * @param string   $group    Grupo de cache.
	 * @param callable $producer Productor del valor.
	 * @param int      $ttl      Vigencia.
	 * @return mixed
	 */
	public function remember( string $key, string $group, callable $producer, int $ttl = 0 ) {
		$found = false;
		$value = $this->cache->get( $key, $group, $found );

		if ( $found ) {
			return $value;
		}

		if ( ! $this->acquire( $key ) ) {
			// Otro proceso esta reconstruyendo: se sirve lo que haya, aunque
			// sea de la entrada obsoleta.
			$stale_found = false;
			$stale       = $this->cache->get( $key . ':stale', $group, $stale_found );

			return $stale_found ? $stale : $producer();
		}

		try {
			$value = $producer();

			$this->cache->set( $key, $value, $group, $ttl );
			$this->cache->set( $key . ':stale', $value, $group, $ttl * 3 );
		} finally {
			$this->release( $key );
		}

		return $value;
	}

	/**
	 * Clave del cerrojo de una clave protegida.
	 *
	 * @param string $key Clave protegida.
	 * @return string
	 */
	private function lock_key( string $key ): string {
		return 'lock:' . md5( $key );
	}
}
