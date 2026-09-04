<?php
/**
 * Cadena de proveedores de autenticacion.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Auth;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WpApi\Codeia\Core\EventDispatcher;

/**
 * Pregunta a cada proveedor por orden hasta que uno reclama la peticion.
 *
 * Si ningun proveedor la reclama, la identidad es anonima: valido, porque los
 * permisos decidiran despues si el recurso admite acceso publico.
 */
final class AuthenticatorChain {

	/**
	 * Proveedores registrados.
	 *
	 * @var Authenticator[]
	 */
	private array $authenticators = array();

	/**
	 * Bus de eventos.
	 *
	 * @var EventDispatcher
	 */
	private EventDispatcher $events;

	/**
	 * Identificador del proveedor que resolvio la ultima peticion.
	 *
	 * @var string
	 */
	private string $resolved_by = '';

	/**
	 * Construye la cadena.
	 *
	 * @param EventDispatcher $events Bus de eventos.
	 */
	public function __construct( EventDispatcher $events ) {
		$this->events = $events;
	}

	/**
	 * Registra un proveedor.
	 *
	 * @param Authenticator $authenticator Proveedor.
	 * @return void
	 */
	public function add( Authenticator $authenticator ): void {
		$this->authenticators[] = $authenticator;
	}

	/**
	 * Proveedores ordenados por prioridad.
	 *
	 * @return Authenticator[]
	 */
	public function authenticators(): array {
		$list = $this->events->filter( 'auth/providers', $this->authenticators );

		if ( ! is_array( $list ) ) {
			$list = $this->authenticators;
		}

		$list = array_values(
			array_filter(
				$list,
				static function ( $item ): bool {
					return $item instanceof Authenticator;
				}
			)
		);

		usort(
			$list,
			static function ( Authenticator $a, Authenticator $b ): int {
				return $a->priority() <=> $b->priority();
			}
		);

		return $list;
	}

	/**
	 * Resuelve la identidad de una peticion.
	 *
	 * @param RequestCredentials $credentials Credenciales.
	 * @return int|WP_Error 0 si es anonima, ID de usuario si se resolvio, o
	 *                      WP_Error si una credencial reclamada es invalida.
	 */
	public function resolve( RequestCredentials $credentials ) {
		$this->resolved_by = '';

		if ( $credentials->is_empty() ) {
			return 0;
		}

		foreach ( $this->authenticators() as $authenticator ) {
			if ( ! $authenticator->handles( $credentials ) ) {
				continue;
			}

			$result = $authenticator->authenticate( $credentials );

			if ( is_wp_error( $result ) ) {
				// Credencial reclamada e invalida: no se sigue probando.
				return $result;
			}

			if ( is_int( $result ) && $result > 0 ) {
				$this->resolved_by = $authenticator->id();

				$this->events->emit( 'auth/authenticated', $result, $authenticator->id() );

				return $result;
			}
		}

		return 0;
	}

	/**
	 * Identificador del proveedor que resolvio la ultima peticion.
	 *
	 * @return string Cadena vacia si no se resolvio ninguna.
	 */
	public function resolved_by(): string {
		return $this->resolved_by;
	}
}
