<?php
/**
 * Credenciales extraidas de la peticion.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Lectura normalizada de las cabeceras de autenticacion.
 *
 * Se extrae una sola vez por peticion y se pasa a la cadena, para que cada
 * proveedor no tenga que repetir la lectura de $_SERVER ni preocuparse de que
 * la cabecera Authorization no llega a PHP en algunos servidores.
 */
final class RequestCredentials {

	/**
	 * Valor completo de la cabecera Authorization.
	 *
	 * @var string
	 */
	private string $authorization;

	/**
	 * Valor de la cabecera de API Key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Construye las credenciales.
	 *
	 * @param string $authorization Cabecera Authorization.
	 * @param string $api_key       Cabecera X-Codeia-Key.
	 */
	public function __construct( string $authorization = '', string $api_key = '' ) {
		$this->authorization = trim( $authorization );
		$this->api_key       = trim( $api_key );
	}

	/**
	 * Extrae las credenciales del entorno de la peticion.
	 *
	 * En algunos servidores la cabecera Authorization no llega a PHP y hay
	 * que recuperarla de la variable de redireccion que anade el .htaccess.
	 *
	 * @param array<string, mixed> $server Copia de $_SERVER.
	 * @return self
	 */
	public static function from_server( array $server ): self {
		$authorization = '';

		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( isset( $server[ $key ] ) && '' !== $server[ $key ] ) {
				$authorization = (string) $server[ $key ];
				break;
			}
		}

		return new self(
			$authorization,
			isset( $server['HTTP_X_CODEIA_KEY'] ) ? (string) $server['HTTP_X_CODEIA_KEY'] : ''
		);
	}

	/**
	 * Indica si no hay credencial alguna.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return '' === $this->authorization && '' === $this->api_key;
	}

	/**
	 * Devuelve el token de un esquema Bearer, o cadena vacia.
	 *
	 * @return string
	 */
	public function bearer(): string {
		if ( 0 !== stripos( $this->authorization, 'bearer ' ) ) {
			return '';
		}

		return trim( substr( $this->authorization, 7 ) );
	}

	/**
	 * Indica si el Bearer tiene forma de JWT.
	 *
	 * JWT y token opaco comparten el esquema Bearer y se distinguen por la
	 * forma del valor. Para que la distincion sea fiable, los tokens opacos
	 * se generan con un alfabeto que EXCLUYE el punto.
	 *
	 * @return bool
	 */
	public function bearer_is_jwt(): bool {
		$token = $this->bearer();

		return '' !== $token && 3 === count( explode( '.', $token ) );
	}

	/**
	 * Devuelve el valor Basic sin decodificar, o cadena vacia.
	 *
	 * @return string
	 */
	public function basic(): string {
		if ( 0 !== stripos( $this->authorization, 'basic ' ) ) {
			return '';
		}

		return trim( substr( $this->authorization, 6 ) );
	}

	/**
	 * Devuelve la API Key.
	 *
	 * @return string
	 */
	public function api_key(): string {
		return $this->api_key;
	}
}
