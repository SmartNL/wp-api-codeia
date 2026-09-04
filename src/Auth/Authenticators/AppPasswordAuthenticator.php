<?php
/**
 * Autenticacion por Application Passwords del nucleo.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Auth\Authenticators;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WpApi\Codeia\Auth\Authenticator;
use WpApi\Codeia\Auth\RequestCredentials;

/**
 * Delega en las Application Passwords que WordPress ya trae.
 *
 * El plugin NO las reimplementa. Aporta gratis: gestion por usuario en su
 * perfil, revocacion individual, registro de ultimo uso y auditoria, todo
 * con superficie de ataque ya auditada por el nucleo.
 *
 * Es la opcion recomendada para integraciones servidor a servidor.
 */
final class AppPasswordAuthenticator implements Authenticator {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return 'app_password';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function priority(): int {
		return 40;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param RequestCredentials $credentials Credenciales.
	 * @return bool
	 */
	public function handles( RequestCredentials $credentials ): bool {
		return '' !== $credentials->basic() && $this->is_available();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param RequestCredentials $credentials Credenciales.
	 * @return int|WP_Error
	 */
	public function authenticate( RequestCredentials $credentials ) {
		$decoded = base64_decode( $credentials->basic(), true );

		if ( false === $decoded || ! str_contains( $decoded, ':' ) ) {
			return $this->invalid();
		}

		list( $username, $password ) = explode( ':', $decoded, 2 );

		$user = wp_authenticate_application_password( null, $username, $password );

		if ( is_wp_error( $user ) || ! $user instanceof \WP_User ) {
			return $this->invalid();
		}

		return $user->ID;
	}

	/**
	 * Indica si el nucleo tiene las Application Passwords disponibles.
	 *
	 * WordPress las exige sobre HTTPS salvo en entornos locales.
	 *
	 * @return bool
	 */
	private function is_available(): bool {
		return function_exists( 'wp_is_application_passwords_available' )
			&& wp_is_application_passwords_available();
	}

	/**
	 * Error unico para cualquier motivo de invalidez.
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
