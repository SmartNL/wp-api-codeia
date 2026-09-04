<?php
/**
 * Utilidades de cadena.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Funciones puras sobre cadenas. Sin estado ni dependencias.
 */
final class Str {

	/**
	 * Convierte a snake_case.
	 *
	 * @param string $value Cadena.
	 * @return string
	 */
	public static function snake( string $value ): string {
		$value = preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $value ) ?? $value;
		$value = strtolower( (string) $value );
		$value = preg_replace( '/[^a-z0-9]+/', '_', $value ) ?? $value;

		return trim( (string) $value, '_' );
	}

	/**
	 * Elimina un prefijo si esta presente.
	 *
	 * @param string $value  Cadena.
	 * @param string $prefix Prefijo.
	 * @return string
	 */
	public static function without_prefix( string $value, string $prefix ): string {
		if ( '' === $prefix || ! str_starts_with( $value, $prefix ) ) {
			return $value;
		}

		return substr( $value, strlen( $prefix ) );
	}

	/**
	 * Trunca una cadena conservando palabras enteras.
	 *
	 * @param string $value  Cadena.
	 * @param int    $length Longitud maxima.
	 * @return string
	 */
	public static function truncate( string $value, int $length ): string {
		if ( $length <= 0 || strlen( $value ) <= $length ) {
			return $value;
		}

		$cut   = substr( $value, 0, $length );
		$space = strrpos( $cut, ' ' );

		return false === $space ? $cut : substr( $cut, 0, $space );
	}
}
