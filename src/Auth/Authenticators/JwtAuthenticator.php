<?php
/**
 * Autenticacion por JWT.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Auth\Authenticators;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WpApi\Codeia\Auth\Authenticator;
use WpApi\Codeia\Auth\Jwt\JwtCodec;
use WpApi\Codeia\Auth\RequestCredentials;
use WpApi\Codeia\Auth\RevocationList;
use WpApi\Codeia\Auth\TokenVersion;

/**
 * Resuelve la identidad a partir de un JWT en la cabecera Authorization.
 */
final class JwtAuthenticator implements Authenticator {

	/**
	 * Codec de tokens.
	 *
	 * @var JwtCodec
	 */
	private JwtCodec $codec;

	/**
	 * Version de token por usuario.
	 *
	 * @var TokenVersion
	 */
	private TokenVersion $versions;

	/**
	 * Lista de revocacion individual.
	 *
	 * @var RevocationList
	 */
	private RevocationList $revoked;

	/**
	 * Construye el autenticador.
	 *
	 * @param JwtCodec       $codec    Codec.
	 * @param TokenVersion   $versions Versiones de token.
	 * @param RevocationList $revoked  Lista de revocacion.
	 */
	public function __construct( JwtCodec $codec, TokenVersion $versions, RevocationList $revoked ) {
		$this->codec    = $codec;
		$this->versions = $versions;
		$this->revoked  = $revoked;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return 'jwt';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function priority(): int {
		return 10;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param RequestCredentials $credentials Credenciales.
	 * @return bool
	 */
	public function handles( RequestCredentials $credentials ): bool {
		return $credentials->bearer_is_jwt();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param RequestCredentials $credentials Credenciales.
	 * @return int|WP_Error
	 */
	public function authenticate( RequestCredentials $credentials ) {
		$claims = $this->codec->decode( $credentials->bearer() );

		if ( null === $claims ) {
			return $this->invalid();
		}

		if ( ! $this->codec->claims_are_current( $claims, time() ) ) {
			return $this->invalid();
		}

		$user_id = isset( $claims['sub'] ) ? (int) $claims['sub'] : 0;

		if ( $user_id <= 0 ) {
			return $this->invalid();
		}

		if ( isset( $claims['jti'] ) && $this->revoked->is_revoked( (string) $claims['jti'] ) ) {
			return $this->invalid();
		}

		if ( ! isset( $claims['tv'] ) || ! $this->versions->is_current( $user_id, (int) $claims['tv'] ) ) {
			return $this->invalid();
		}

		return $user_id;
	}

	/**
	 * Error unico para cualquier motivo de invalidez.
	 *
	 * El cliente recibe siempre lo mismo, sin distinguir caducidad de firma
	 * incorrecta ni de token revocado: distinguirlos convertiria el endpoint
	 * en un oraculo. El motivo real va al log.
	 *
	 * @return WP_Error
	 */
	private function invalid(): WP_Error {
		return new WP_Error(
			'codeia_auth_invalid',
			__( 'La credencial no es valida.', 'wp-api-codeia' ),
			array( 'status' => 401 )
		);
	}
}
