<?php
/**
 * Contenedor de inyeccion de dependencias.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\Exceptions\ContainerException;
use WpApi\Codeia\Core\Exceptions\NotFoundException;

/**
 * Contenedor DI ligero con resolucion perezosa.
 *
 * Compatible con la firma de Psr\Container\ContainerInterface (get/has) pero
 * sin implementarla: arrastrar psr/container como dependencia de runtime
 * expone el plugin a conflictos de version con otros plugins de la
 * instalacion, y el ecosistema de WordPress no aisla dependencias.
 *
 * Los servicios se declaran como factorias y solo se construyen la primera
 * vez que se piden. La instancia resultante se cachea.
 */
final class Container {

	/**
	 * Factorias registradas, indexadas por identificador.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Instancias ya construidas.
	 *
	 * @var array<string, mixed>
	 */
	private array $instances = array();

	/**
	 * Identificadores en curso de resolucion, para detectar ciclos.
	 *
	 * @var string[]
	 */
	private array $resolving = array();

	/**
	 * Registra una factoria.
	 *
	 * La factoria recibe el propio contenedor y devuelve el servicio. No se
	 * ejecuta hasta que alguien pide el identificador.
	 *
	 * @param string   $id      Identificador, normalmente el FQCN.
	 * @param callable $factory Factoria que construye el servicio.
	 * @return void
	 *
	 * @throws ContainerException Si el identificador ya estaba registrado.
	 */
	public function set( string $id, callable $factory ): void {
		if ( $this->has( $id ) ) {
			throw ContainerException::already_registered( $id );
		}

		$this->factories[ $id ] = $factory;
	}

	/**
	 * Registra una instancia ya construida.
	 *
	 * Util para valores de configuracion y para sustituir servicios en tests.
	 * A diferencia de set(), sobrescribe sin protestar: es justo lo que un
	 * test necesita para inyectar un doble.
	 *
	 * @param string $id    Identificador.
	 * @param mixed  $value Instancia o valor.
	 * @return void
	 */
	public function instance( string $id, mixed $value ): void {
		$this->instances[ $id ] = $value;
		unset( $this->factories[ $id ] );
	}

	/**
	 * Indica si el identificador esta registrado.
	 *
	 * @param string $id Identificador.
	 * @return bool
	 */
	public function has( string $id ): bool {
		// array_key_exists y no isset: una instancia registrada con valor null
		// existe, aunque isset() diga lo contrario.
		return array_key_exists( $id, $this->instances )
			|| array_key_exists( $id, $this->factories );
	}

	/**
	 * Resuelve un servicio.
	 *
	 * @param string $id Identificador.
	 * @return mixed
	 *
	 * @throws NotFoundException  Si el identificador no esta registrado.
	 * @throws ContainerException Si se detecta una dependencia circular.
	 */
	public function get( string $id ): mixed {
		if ( array_key_exists( $id, $this->instances ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw NotFoundException::for_id( $id );
		}

		if ( in_array( $id, $this->resolving, true ) ) {
			throw ContainerException::circular_dependency( $id, $this->resolving );
		}

		$this->resolving[] = $id;

		try {
			$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );
		} finally {
			// El pop va en finally para que una factoria que lance no deje la
			// cadena de resolucion sucia y envenene las llamadas siguientes.
			array_pop( $this->resolving );
		}

		return $this->instances[ $id ];
	}

	/**
	 * Devuelve los identificadores registrados.
	 *
	 * @return string[]
	 */
	public function keys(): array {
		return array_values(
			array_unique(
				array_merge(
					array_keys( $this->instances ),
					array_keys( $this->factories )
				)
			)
		);
	}
}
