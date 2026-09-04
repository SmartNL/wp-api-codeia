<?php
/**
 * Utilidades de array.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Funciones puras sobre arrays. Sin estado ni dependencias.
 */
final class Arr {

	/**
	 * Lee un valor anidado con notacion de punto.
	 *
	 * @param array<string, mixed> $data     Array.
	 * @param string               $path     Ruta.
	 * @param mixed                $fallback Valor si la ruta no existe.
	 * @return mixed
	 */
	public static function get( array $data, string $path, $fallback = null ) {
		$cursor = $data;

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
				return $fallback;
			}

			$cursor = $cursor[ $segment ];
		}

		return $cursor;
	}

	/**
	 * Escribe un valor anidado con notacion de punto.
	 *
	 * @param array<string, mixed> $data  Array, modificado por copia.
	 * @param string               $path  Ruta.
	 * @param mixed                $value Valor.
	 * @return array<string, mixed>
	 */
	public static function set( array $data, string $path, $value ): array {
		$segments = explode( '.', $path );
		$cursor   = &$data;

		foreach ( $segments as $segment ) {
			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				$cursor[ $segment ] = array();
			}

			$cursor = &$cursor[ $segment ];
		}

		$cursor = $value;

		return $data;
	}

	/**
	 * Conserva solo las claves indicadas.
	 *
	 * @param array<string, mixed> $data Array.
	 * @param string[]             $keys Claves a conservar.
	 * @return array<string, mixed>
	 */
	public static function only( array $data, array $keys ): array {
		return array_intersect_key( $data, array_flip( $keys ) );
	}

	/**
	 * Elimina las claves indicadas.
	 *
	 * @param array<string, mixed> $data Array.
	 * @param string[]             $keys Claves a eliminar.
	 * @return array<string, mixed>
	 */
	public static function except( array $data, array $keys ): array {
		return array_diff_key( $data, array_flip( $keys ) );
	}

	/**
	 * Indica si el array es una lista con indices consecutivos.
	 *
	 * @param array<mixed> $data Array.
	 * @return bool
	 */
	public static function is_list( array $data ): bool {
		if ( array() === $data ) {
			return true;
		}

		return array_keys( $data ) === range( 0, count( $data ) - 1 );
	}
}
