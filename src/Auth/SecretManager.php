<?php
/**
 * Gestion del secreto de firma.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Resuelve el secreto con el que se firman los JWT.
 *
 * Por orden de preferencia: una constante en wp-config.php, o un secreto de
 * 256 bits generado en la primera necesidad y guardado en opcion propia.
 *
 * NUNCA se reutiliza AUTH_KEY ni ninguna sal del nucleo: comparten proposito
 * con las cookies de sesion, y un secreto compartido entre dos sistemas de
 * credenciales convierte la fuga de uno en el compromiso de ambos. La opcion
 * propia permite ademas rotar sin invalidar las sesiones del admin.
 */
final class SecretManager {

	/**
	 * Opcion donde se guarda el secreto generado.
	 */
	public const OPTION = 'codeia_jwt_secret';

	/**
	 * Constante que, si existe, tiene prioridad.
	 */
	public const CONSTANT = 'CODEIA_JWT_SECRET';

	/**
	 * Devuelve el secreto de firma, generandolo si hace falta.
	 *
	 * @return string
	 */
	public function secret(): string {
		if ( defined( self::CONSTANT ) ) {
			$value = (string) constant( self::CONSTANT );

			if ( '' !== $value ) {
				return $value;
			}
		}

		$stored = get_option( self::OPTION, '' );

		if ( is_string( $stored ) && '' !== $stored ) {
			return $stored;
		}

		return $this->rotate();
	}

	/**
	 * Genera un secreto nuevo, invalidando todos los tokens del sitio.
	 *
	 * @return string
	 */
	public function rotate(): string {
		$secret = bin2hex( random_bytes( 32 ) );

		update_option( self::OPTION, $secret, false );

		return $secret;
	}

	/**
	 * Indica si el secreto viene de una constante y no puede rotarse.
	 *
	 * @return bool
	 */
	public function is_fixed(): bool {
		return defined( self::CONSTANT ) && '' !== (string) constant( self::CONSTANT );
	}

	/**
	 * Emisor esperado en los tokens.
	 *
	 * @return string
	 */
	public function issuer(): string {
		return home_url();
	}
}
