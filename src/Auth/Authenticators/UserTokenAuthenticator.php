<?php
/**
 * Autenticacion por token opaco de usuario.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Auth\Authenticators;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WpApi\Codeia\Auth\Authenticator;
use WpApi\Codeia\Auth\OpaqueToken;
use WpApi\Codeia\Auth\RequestCredentials;
use WpApi\Codeia\Auth\TokenRepository;

/**
 * Credencial opaca de larga duracion ligada a un usuario.
 *
 * Existe para instalaciones donde las Application Passwords estan
 * desactivadas —por politica, o por servirse sobre HTTP en redes internas— y
 * hace falta una credencial estable sin montar JWT.
 */
final class UserTokenAuthenticator implements Authenticator {

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
		return 'user_token';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function priority(): int {
		return 20;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param RequestCredentials $credentials Credenciales.
	 * @return bool
	 */
	public function handles( RequestCredentials $credentials ): bool {
		$token = $credentials->bearer();

		return '' !== $token && ! $credentials->bearer_is_jwt() && OpaqueToken::is_well_formed( $token );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param RequestCredentials $credentials Credenciales.
	 * @return int|WP_Error
	 */
	public function authenticate( RequestCredentials $credentials ) {
		$user_id = $this->tokens->resolve( $credentials->bearer(), TokenRepository::TYPE_USER );

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
