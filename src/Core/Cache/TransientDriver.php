<?php
/**
 * Driver sobre transients.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Core\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Almacen de respaldo cuando no hay object cache persistente.
 *
 * Funciona, pero cada escritura es una consulta a wp_options y las entradas
 * caducadas no se borran solas: se limpian al leerlas o en el cron del nucleo.
 * Por eso el CacheManager reduce los TTL cuando este es el driver activo.
 */
final class TransientDriver implements CacheInterface {

	/**
	 * Longitud maxima del nombre de un transient en wp_options.
	 *
	 * La columna option_name admite 191 caracteres y WordPress antepone
	 * "_transient_timeout_" (19). Se deja margen para el prefijo mas largo.
	 */
	private const MAX_KEY_LENGTH = 172;

	/**
	 * {@inheritDoc}
	 *
	 * @param string    $key   Clave.
	 * @param string    $group Grupo.
	 * @param bool|null $found Recibe si la clave existia.
	 * @return mixed
	 */
	public function get( string $key, string $group, ?bool &$found = null ): mixed {
		$value = get_transient( $this->build_key( $key, $group ) );

		// get_transient() devuelve false tanto si no existe como si el valor
		// almacenado era false. Se guarda envuelto para poder distinguirlo.
		if ( ! is_array( $value ) || ! array_key_exists( 'v', $value ) ) {
			$found = false;
			return null;
		}

		$found = true;

		return $value['v'];
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
		return set_transient( $this->build_key( $key, $group ), array( 'v' => $value ), $ttl );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key   Clave.
	 * @param string $group Grupo.
	 * @return bool
	 */
	public function delete( string $key, string $group ): bool {
		return delete_transient( $this->build_key( $key, $group ) );
	}

	/**
	 * Compone el nombre del transient a partir de grupo y clave.
	 *
	 * Si el resultado excede el limite de option_name se sustituye por un
	 * hash: un nombre truncado provocaria colisiones silenciosas entre claves
	 * que compartan prefijo, que es justo lo que hacen las claves versionadas.
	 *
	 * @param string $key   Clave.
	 * @param string $group Grupo.
	 * @return string
	 */
	private function build_key( string $key, string $group ): string {
		$name = $group . ':' . $key;

		if ( strlen( $name ) > self::MAX_KEY_LENGTH ) {
			return $group . ':h:' . md5( $key );
		}

		return $name;
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
		return 'transient';
	}
}
