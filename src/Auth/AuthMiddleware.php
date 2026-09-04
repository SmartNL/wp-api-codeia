<?php
/**
 * Middleware de autenticacion sobre los hooks del nucleo.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Auth;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Conecta la cadena de autenticacion con WordPress.
 *
 * Se engancha en determine_current_user y NO en rest_pre_dispatch. Ese
 * segundo hook se ejecuta despues de que el nucleo haya resuelto el usuario
 * actual: autenticar ahi obligaria a llamar a wp_set_current_user() a mano
 * con el usuario ya fijado a 0, y todo lo evaluado antes veria un anonimo.
 * El resultado son incoherencias sutiles, con current_user_can() devolviendo
 * distinto en dos puntos de la misma peticion.
 */
final class AuthMiddleware {

	/**
	 * Prioridad en determine_current_user.
	 *
	 * Por encima de la cookie (10) y por debajo de las Application Passwords
	 * del nucleo (20): si la peticion trae token propio se resuelve primero,
	 * sin interferir con la sesion de cookie del administrador que este
	 * navegando el dashboard en otra pestana.
	 */
	public const PRIORITY = 15;

	/**
	 * Cadena de proveedores.
	 *
	 * @var AuthenticatorChain
	 */
	private AuthenticatorChain $chain;

	/**
	 * Bandera de reentrada.
	 *
	 * Llamar a get_current_user_id() o current_user_can() dentro del callback
	 * de determine_current_user reentra en el mismo filtro y provoca
	 * recursion infinita.
	 *
	 * @var bool
	 */
	private bool $resolving = false;

	/**
	 * Error de la ultima resolucion, si lo hubo.
	 *
	 * @var WP_Error|null
	 */
	private ?WP_Error $error = null;

	/**
	 * Si la ultima peticion se autentico por token.
	 *
	 * @var bool
	 */
	private bool $authenticated = false;

	/**
	 * Construye el middleware.
	 *
	 * @param AuthenticatorChain $chain Cadena de proveedores.
	 */
	public function __construct( AuthenticatorChain $chain ) {
		$this->chain = $chain;
	}

	/**
	 * Engancha el middleware.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'determine_current_user', array( $this, 'determine_user' ), self::PRIORITY );
		add_filter( 'rest_authentication_errors', array( $this, 'report_errors' ) );
	}

	/**
	 * Resuelve el usuario actual a partir de las credenciales.
	 *
	 * @param int|false $user_id Usuario resuelto por filtros anteriores.
	 * @return int|false
	 */
	public function determine_user( $user_id ) {
		if ( $this->resolving || ! empty( $user_id ) ) {
			// Ya resuelto por cookie: se respeta.
			return $user_id;
		}

		$this->resolving     = true;
		$this->error         = null;
		$this->authenticated = false;

		// Solo trabaja con datos de la peticion: nunca llama a
		// current_user_can() ni a get_current_user_id().
		$result = $this->chain->resolve( RequestCredentials::from_server( $_SERVER ) );

		$this->resolving = false;

		if ( is_wp_error( $result ) ) {
			$this->error = $result;

			return $user_id;
		}

		if ( $result > 0 ) {
			$this->authenticated = true;

			return $result;
		}

		return $user_id;
	}

	/**
	 * Informa al REST server del resultado de la autenticacion.
	 *
	 * Devolver true cortocircuita rest_cookie_check_errors, que exigiria un
	 * nonce X-WP-Nonce que un cliente externo no tiene.
	 *
	 * CRITICO: solo se devuelve true cuando un proveedor ha resuelto
	 * positivamente una identidad. Devolverlo de forma incondicional
	 * desactivaria la proteccion CSRF de las peticiones por cookie en toda la
	 * instalacion.
	 *
	 * @param WP_Error|bool|null $errors Estado previo.
	 * @return WP_Error|bool|null
	 */
	public function report_errors( $errors ) {
		if ( null !== $this->error ) {
			return $this->error;
		}

		if ( $this->authenticated ) {
			return true;
		}

		return $errors;
	}

	/**
	 * Indica si la peticion actual se autentico por token.
	 *
	 * @return bool
	 */
	public function authenticated_by_token(): bool {
		return $this->authenticated;
	}
}
