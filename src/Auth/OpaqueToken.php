<?php
/**
 * Generacion de credenciales opacas.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Genera y compara credenciales opacas: API Keys y tokens de usuario.
 *
 * El alfabeto EXCLUYE el punto a proposito. JWT y token opaco comparten el
 * esquema Bearer y se distinguen por la forma del valor; si un token opaco
 * pudiera contener puntos, la distincion dejaria de ser fiable.
 */
final class OpaqueToken {

	/**
	 * Bytes de entropia de cada credencial.
	 */
	private const BYTES = 32;

	/**
	 * Genera una credencial nueva.
	 *
	 * @param string $prefix Prefijo identificativo, por ejemplo "ck_".
	 * @return string
	 */
	public static function generate( string $prefix = '' ): string {
		$raw = rtrim( strtr( base64_encode( random_bytes( self::BYTES ) ), '+/', '-_' ), '=' );

		return $prefix . $raw;
	}

	/**
	 * Calcula el hash de almacenamiento de una credencial.
	 *
	 * Se usa SHA-256 y no wp_hash_password(): una credencial de 256 bits de
	 * entropia aleatoria no necesita el coste de bcrypt, cuyo proposito es
	 * frenar el ataque por diccionario contra contrasenas humanas de baja
	 * entropia. Aqui bcrypt solo anadiria latencia a cada peticion.
	 *
	 * El razonamiento NO aplica a contrasenas elegidas por personas.
	 *
	 * @param string $token Credencial en claro.
	 * @return string
	 */
	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Compara una credencial con su hash en tiempo constante.
	 *
	 * @param string $token Credencial en claro.
	 * @param string $hash  Hash almacenado.
	 * @return bool
	 */
	public static function matches( string $token, string $hash ): bool {
		return hash_equals( $hash, self::hash( $token ) );
	}

	/**
	 * Comprueba que la credencial tiene la forma esperada.
	 *
	 * @param string $token Credencial.
	 * @return bool
	 */
	public static function is_well_formed( string $token ): bool {
		return '' !== $token && ! str_contains( $token, '.' );
	}
}
