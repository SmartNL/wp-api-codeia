<?php
/**
 * Revocacion masiva por version de token.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Invalida de golpe todos los tokens de un usuario.
 *
 * Es el mecanismo principal de revocacion: un entero en user meta que se
 * incrusta en el claim "tv" al emitir y se compara en cada validacion.
 * Incrementarlo invalida todos los tokens del usuario con UNA escritura y
 * sin mantener listas que crezcan.
 */
final class TokenVersion {

	/**
	 * Clave de user meta donde vive el contador.
	 */
	public const META_KEY = '_codeia_token_version';

	/**
	 * Devuelve la version actual de un usuario.
	 *
	 * @param int $user_id ID de usuario.
	 * @return int
	 */
	public function current( int $user_id ): int {
		$value = get_user_meta( $user_id, self::META_KEY, true );

		return '' === $value || false === $value ? 1 : (int) $value;
	}

	/**
	 * Incrementa la version, invalidando todos los tokens del usuario.
	 *
	 * @param int $user_id ID de usuario.
	 * @return int Nueva version.
	 */
	public function bump( int $user_id ): int {
		$next = $this->current( $user_id ) + 1;

		update_user_meta( $user_id, self::META_KEY, $next );

		return $next;
	}

	/**
	 * Comprueba que la version de un token sigue vigente.
	 *
	 * @param int $user_id ID de usuario.
	 * @param int $version Version que declara el token.
	 * @return bool
	 */
	public function is_current( int $user_id, int $version ): bool {
		return $version === $this->current( $user_id );
	}

	/**
	 * Engancha los eventos que deben invalidar tokens.
	 *
	 * Cambiar la contrasena o el rol tiene que dejar fuera a los tokens
	 * emitidos antes: si no, revocar el acceso de alguien no surtiria efecto
	 * hasta que su token caducase por si solo.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action(
			'after_password_reset',
			function ( $user ): void {
				if ( $user instanceof \WP_User ) {
					$this->bump( $user->ID );
				}
			}
		);

		add_action(
			'profile_update',
			function ( $user_id, $old_data ): void {
				$user = get_userdata( (int) $user_id );

				if ( $user instanceof \WP_User && isset( $old_data->user_pass )
					&& $user->user_pass !== $old_data->user_pass ) {
					$this->bump( (int) $user_id );
				}
			},
			10,
			2
		);

		add_action(
			'set_user_role',
			function ( $user_id ): void {
				$this->bump( (int) $user_id );
			}
		);
	}
}
