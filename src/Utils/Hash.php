<?php
/**
 * Utilidades de hash.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Hashes cortos y estables para claves de cache.
 *
 * No son hashes criptograficos de credenciales: para eso esta OpaqueToken.
 * Aqui solo se busca una huella corta y determinista.
 */
final class Hash {

	/**
	 * Longitud por defecto de una huella corta.
	 */
	public const SHORT_LENGTH = 8;

	/**
	 * Huella corta y estable de un valor arbitrario.
	 *
	 * @param mixed $value  Valor.
	 * @param int   $length Longitud.
	 * @return string
	 */
	public static function short( $value, int $length = self::SHORT_LENGTH ): string {
		$serialized = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );

		return substr( md5( $serialized ), 0, max( 4, $length ) );
	}

	/**
	 * Huella de un conjunto de cadenas, independiente del orden.
	 *
	 * @param string[] $items  Elementos.
	 * @param int      $length Longitud.
	 * @return string
	 */
	public static function of_set( array $items, int $length = self::SHORT_LENGTH ): string {
		$items = array_map( 'strval', $items );
		sort( $items );

		return self::short( implode( ',', $items ), $length );
	}
}
