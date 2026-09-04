<?php
/**
 * Autenticacion por API Key.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Auth\Authenticators;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WpApi\Codeia\Auth\Authenticator;
use WpApi\Codeia\Auth\RequestCredentials;
use WpApi\Codeia\Auth\TokenRepository;

/**
 * Credencial opaca pensada para servicios, no para usuarios finales.
 *
 * Frente a JWT: no caduca —adecuado para un cron externo—, se revoca de
 * inmediato sin ventana de validez residual, y puede tener un ambito propio
 * mas estrecho que el rol del usuario.
 */
final class ApiKeyAuthenticator implements Authenticator {

	/**
	 * Almacen de credenciales.
	 *
	 * @var TokenRepository
	 */
	private TokenRepository $tokens;

	/**
	 * Construye el autenticador.
	 *
	 * @param TokenRepository $tokens Almacen de credenciales.
	 */
	public function __construct( TokenRepository $tokens ) {
		$this->tokens = $tokens;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return 'api_key';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function priority(): int {
		return 30;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param RequestCredentials $credentials Credenciales.
	 * @return bool
	 */
	public function handles( RequestCredentials $credentials ): bool {
		return '' !== $credentials->api_key();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param RequestCredentials $credentials Credenciales.
	 * @return int|WP_Error
	 */
	public function authenticate( RequestCredentials $credentials ) {
		$user_id = $this->tokens->resolve( $credentials->api_key(), TokenRepository::TYPE_API_KEY );

		if ( $user_id <= 0 ) {
			return new WP_Error(
				'codeia_auth_invalid',
				__( 'La credencial no es valida.', 'wp-api-codeia' ),
				array( 'status' => 401 )
			);
		}

		return $user_id;
	}
}
