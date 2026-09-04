<?php
/**
 * Contrato de los drivers de cache.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Core\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Almacen clave-valor agrupado.
 *
 * Los grupos permiten separar espacios de nombres (esquema, respuestas,
 * OpenAPI) sin colisiones. La invalidacion NO se hace borrando grupos:
 * wp_cache_flush_group() no esta soportado por todos los backends de object
 * cache, asi que las claves llevan una version incrustada y las entradas
 * viejas quedan huerfanas hasta expirar.
 */
interface CacheInterface {

	/**
	 * Lee un valor.
	 *
	 * @param string    $key   Clave.
	 * @param string    $group Grupo.
	 * @param bool|null $found Recibe true si la clave existia. Distingue un
	 *                         valor nulo o false almacenado de un fallo.
	 * @return mixed Valor almacenado, o null si no existe.
	 */
	public function get( string $key, string $group, ?bool &$found = null ): mixed;

	/**
	 * Escribe un valor.
	 *
	 * @param string $key   Clave.
	 * @param mixed  $value Valor.
	 * @param string $group Grupo.
	 * @param int    $ttl   Segundos de vida. 0 significa sin expiracion.
	 * @return bool
	 */
	public function set( string $key, mixed $value, string $group, int $ttl = 0 ): bool;

	/**
	 * Borra una clave.
	 *
	 * @param string $key   Clave.
	 * @param string $group Grupo.
	 * @return bool
	 */
	public function delete( string $key, string $group ): bool;

	/**
	 * Indica si el almacen sobrevive a la peticion.
	 *
	 * @return bool
	 */
	public function is_persistent(): bool;

	/**
	 * Identificador corto del driver, para diagnostico.
	 *
	 * @return string
	 */
	public function name(): string;
}
