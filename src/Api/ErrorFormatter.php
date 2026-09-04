<?php
/**
 * Errores normalizados de la API.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Api;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Compone los errores con codigo, mensaje y estado coherentes.
 *
 * Los mensajes hacia fuera son deliberadamente poco informativos. La regla
 * que gobierna varios de ellos: nunca confirmar la existencia de algo que
 * quien pregunta no deberia poder ver.
 */
final class ErrorFormatter {

	/**
	 * El elemento no existe, o existe y el rol no puede verlo.
	 *
	 * Se responde 404 y no 403 a proposito: un 403 confirmaria que el
	 * elemento existe.
	 *
	 * @return WP_Error
	 */
	public static function not_found(): WP_Error {
		return new WP_Error(
			'codeia_not_found',
			__( 'El recurso solicitado no existe.', 'wp-api-codeia' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Sin permiso para la operacion.
	 *
	 * @return WP_Error
	 */
	public static function forbidden(): WP_Error {
		return new WP_Error(
			'codeia_forbidden',
			__( 'No tienes permiso para realizar esta operacion.', 'wp-api-codeia' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Campo inexistente, o existente pero invisible para el rol.
	 *
	 * Ambos casos dan el MISMO error: si un rol no puede ver un campo,
	 * tampoco debe enterarse de que existe.
	 *
	 * @param string $field Nombre del campo.
	 * @return WP_Error
	 */
	public static function unknown_field( string $field ): WP_Error {
		return new WP_Error(
			'codeia_unknown_field',
			sprintf(
				/* translators: %s: nombre del campo. */
				__( 'El campo "%s" no existe.', 'wp-api-codeia' ),
				$field
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Campo visible pero no escribible por este rol.
	 *
	 * Aqui SI se distingue de unknown_field: el rol ya sabe que el campo
	 * existe, asi que ocultarlo no aportaria nada.
	 *
	 * @param string[] $fields Campos vetados.
	 * @return WP_Error
	 */
	public static function field_forbidden( array $fields ): WP_Error {
		return new WP_Error(
			'codeia_field_forbidden',
			sprintf(
				/* translators: %s: lista de campos separados por coma. */
				__( 'No tienes permiso para modificar los campos: %s.', 'wp-api-codeia' ),
				implode( ', ', $fields )
			),
			array(
				'status' => 403,
				'fields' => array_values( $fields ),
			)
		);
	}

	/**
	 * Campo real pero no habilitado para filtrar.
	 *
	 * @param string $field Campo.
	 * @return WP_Error
	 */
	public static function not_filterable( string $field ): WP_Error {
		return new WP_Error(
			'codeia_field_not_filterable',
			sprintf(
				/* translators: %s: nombre del campo. */
				__( 'El campo "%s" no esta habilitado para filtrar.', 'wp-api-codeia' ),
				$field
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Se supera el maximo de clausulas meta.
	 *
	 * Sin este tope, una sola URL puede tumbar la base de datos: cada
	 * clausula es un JOIN adicional sobre wp_postmeta.
	 *
	 * @param int $max Maximo permitido.
	 * @return WP_Error
	 */
	public static function query_too_complex( int $max ): WP_Error {
		return new WP_Error(
			'codeia_query_too_complex',
			sprintf(
				/* translators: %d: numero maximo de filtros. */
				__( 'La consulta tiene demasiados filtros. El maximo es %d.', 'wp-api-codeia' ),
				$max
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Cursor mal formado o con firma invalida.
	 *
	 * @return WP_Error
	 */
	public static function invalid_cursor(): WP_Error {
		return new WP_Error(
			'codeia_invalid_cursor',
			__( 'El cursor de paginacion no es valido.', 'wp-api-codeia' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Escritura concurrente detectada.
	 *
	 * @return WP_Error
	 */
	public static function conflict(): WP_Error {
		return new WP_Error(
			'codeia_conflict',
			__( 'El recurso ha cambiado desde la ultima lectura.', 'wp-api-codeia' ),
			array( 'status' => 409 )
		);
	}
}
