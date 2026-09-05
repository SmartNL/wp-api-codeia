<?php
/**
 * Cabeceras ETag para cache en cliente.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Core;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Convierte una respuesta sin cambios en un 304.
 *
 * Es la mejora con mejor relacion coste/beneficio del documento de
 * rendimiento: transforma una respuesta de 200 KB en unas cabeceras cuando
 * nada ha cambiado.
 */
final class EtagManager {

	/**
	 * Calcula el ETag de un cuerpo.
	 *
	 * @param mixed $data Datos de la respuesta.
	 * @return string
	 */
	public function compute( $data ): string {
		return '"' . md5( (string) wp_json_encode( $data ) ) . '"';
	}

	/**
	 * Aplica el ETag y decide si procede un 304.
	 *
	 * @param WP_REST_Response $response Respuesta.
	 * @param WP_REST_Request  $request  Peticion.
	 * @return WP_REST_Response
	 */
	public function apply( WP_REST_Response $response, WP_REST_Request $request ): WP_REST_Response {
		if ( 200 !== $response->get_status() ) {
			return $response;
		}

		$etag = $this->compute( $response->get_data() );
		$response->header( 'ETag', $etag );

		$received = (string) $request->get_header( 'if_none_match' );

		if ( '' !== $received && $this->matches( $received, $etag ) ) {
			$response->set_status( 304 );
			$response->set_data( null );
		}

		return $response;
	}

	/**
	 * Compara el ETag recibido con el calculado.
	 *
	 * Se admite la forma debil (W/) porque algunos proxies la anaden.
	 *
	 * @param string $received Valor de If-None-Match.
	 * @param string $current  ETag actual.
	 * @return bool
	 */
	public function matches( string $received, string $current ): bool {
		foreach ( explode( ',', $received ) as $candidate ) {
			$candidate = trim( $candidate );
			$candidate = str_starts_with( $candidate, 'W/' ) ? substr( $candidate, 2 ) : $candidate;

			if ( '*' === $candidate || $candidate === $current ) {
				return true;
			}
		}

		return false;
	}
}
