<?php
/**
 * Ventana deslizante de dos contadores.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Security;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\Cache\CacheManager;

/**
 * Cuenta peticiones con coste constante y sin ráfagas en el borde.
 *
 * Cada identidad tiene dos contadores: el de la ventana actual y el de la
 * anterior. El consumo estimado pondera la anterior por la fraccion de
 * ventana transcurrida.
 *
 * La ventana fija, mas simple, permite 120 peticiones al final de un minuto
 * y 120 al principio del siguiente: 240 en dos segundos. Esta variante lo
 * evita con solo dos enteros por identidad.
 */
final class SlidingWindow {

	/**
	 * Grupo de cache de los contadores.
	 */
	private const GROUP = 'codeia_rl';

	/**
	 * Gestor de cache.
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache;

	/**
	 * Construye la ventana.
	 *
	 * @param CacheManager $cache Gestor de cache.
	 */
	public function __construct( CacheManager $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Registra un consumo y devuelve el estado del limite.
	 *
	 * @param string $key    Identidad.
	 * @param int    $limit  Maximo por ventana.
	 * @param int    $window Duracion de la ventana en segundos.
	 * @param int    $now    Marca de tiempo, inyectable para tests.
	 * @return array<string, mixed>
	 */
	public function hit( string $key, int $limit, int $window, ?int $now = null ): array {
		$now    = $now ?? time();
		$window = max( 1, $window );

		$current_slot  = (int) floor( $now / $window );
		$previous_slot = $current_slot - 1;

		$current  = (int) $this->read( $key, $current_slot );
		$previous = (int) $this->read( $key, $previous_slot );

		$elapsed  = ( $now % $window ) / $window;
		$estimate = $current + (int) round( $previous * ( 1 - $elapsed ) );

		if ( $estimate >= $limit ) {
			return $this->result( false, $limit, 0, $window, $now );
		}

		$this->write( $key, $current_slot, $current + 1, $window * 2 );

		return $this->result( true, $limit, max( 0, $limit - $estimate - 1 ), $window, $now );
	}

	/**
	 * Consulta el estado sin consumir.
	 *
	 * @param string $key    Identidad.
	 * @param int    $limit  Maximo por ventana.
	 * @param int    $window Duracion de la ventana.
	 * @param int    $now    Marca de tiempo.
	 * @return array<string, mixed>
	 */
	public function peek( string $key, int $limit, int $window, ?int $now = null ): array {
		$now    = $now ?? time();
		$window = max( 1, $window );

		$current_slot = (int) floor( $now / $window );

		$current  = (int) $this->read( $key, $current_slot );
		$previous = (int) $this->read( $key, $current_slot - 1 );

		$elapsed  = ( $now % $window ) / $window;
		$estimate = $current + (int) round( $previous * ( 1 - $elapsed ) );

		return $this->result( $estimate < $limit, $limit, max( 0, $limit - $estimate ), $window, $now );
	}

	/**
	 * Compone el resultado.
	 *
	 * @param bool $allowed   Si la peticion cabe.
	 * @param int  $limit     Limite.
	 * @param int  $remaining Restantes.
	 * @param int  $window    Ventana.
	 * @param int  $now       Marca de tiempo.
	 * @return array<string, mixed>
	 */
	private function result( bool $allowed, int $limit, int $remaining, int $window, int $now ): array {
		$reset = ( ( (int) floor( $now / $window ) ) + 1 ) * $window;

		return array(
			'allowed'     => $allowed,
			'limit'       => $limit,
			'remaining'   => $remaining,
			'reset'       => $reset,
			'retry_after' => max( 1, $reset - $now ),
		);
	}

	/**
	 * Lee un contador.
	 *
	 * @param string $key  Identidad.
	 * @param int    $slot Ventana.
	 * @return int
	 */
	private function read( string $key, int $slot ): int {
		$found = false;
		$value = $this->cache->get( $key . ':' . $slot, self::GROUP, $found );

		return $found ? (int) $value : 0;
	}

	/**
	 * Escribe un contador.
	 *
	 * @param string $key   Identidad.
	 * @param int    $slot  Ventana.
	 * @param int    $value Valor.
	 * @param int    $ttl   Vigencia.
	 * @return void
	 */
	private function write( string $key, int $slot, int $value, int $ttl ): void {
		$this->cache->set( $key . ':' . $slot, $value, self::GROUP, $ttl );
	}
}
