<?php
/**
 * Cache en memoria de la peticion.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Core\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Almacen en memoria que vive lo que dura la peticion.
 *
 * Actua SIEMPRE como primer nivel por delante del driver persistente. Componer
 * una respuesta de veinte elementos pide el esquema del recurso al menos una
 * vez por elemento; sin este nivel serian veinte lecturas del object cache que
 * aqui se reducen a una.
 *
 * No aplica TTL: dentro de una misma peticion no hay tiempo suficiente para
 * que un valor caduque de forma significativa.
 */
final class MemoryDriver implements CacheInterface {

	/**
	 * Valores almacenados, indexados por grupo y clave.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param string    $key   Clave.
	 * @param string    $group Grupo.
	 * @param bool|null $found Recibe si la clave existia.
	 * @return mixed
	 */
	public function get( string $key, string $group, ?bool &$found = null ): mixed {
		$found = isset( $this->store[ $group ] ) && array_key_exists( $key, $this->store[ $group ] );

		return $found ? $this->store[ $group ][ $key ] : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key   Clave.
	 * @param mixed  $value Valor.
	 * @param string $group Grupo.
	 * @param int    $ttl   Ignorado en este driver.
	 * @return bool
	 */
	public function set( string $key, mixed $value, string $group, int $ttl = 0 ): bool {
		$this->store[ $group ][ $key ] = $value;

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key   Clave.
	 * @param string $group Grupo.
	 * @return bool
	 */
	public function delete( string $key, string $group ): bool {
		if ( ! isset( $this->store[ $group ] ) || ! array_key_exists( $key, $this->store[ $group ] ) ) {
			return false;
		}

		unset( $this->store[ $group ][ $key ] );

		return true;
	}

	/**
	 * Vacia el almacen completo o un grupo concreto.
	 *
	 * Aqui si es viable borrar por grupo: el almacen es un array propio, no un
	 * backend externo con capacidades variables.
	 *
	 * @param string|null $group Grupo a vaciar, o null para vaciarlo todo.
	 * @return void
	 */
	public function flush( ?string $group = null ): void {
		if ( null === $group ) {
			$this->store = array();
			return;
		}

		unset( $this->store[ $group ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_persistent(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name(): string {
		return 'memory';
	}
}
