<?php
/**
 * Paginacion por cursor.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Codifica y verifica cursores keyset.
 *
 * Frente al offset: coste constante a cualquier profundidad y estabilidad
 * ante inserciones. Con offset, publicar un elemento mientras se pagina
 * desplaza todo y provoca repeticiones o saltos.
 *
 * El cursor no es secreto —solo lleva fecha e ID— pero va firmado con HMAC:
 * uno alterado produciria consultas con valores no validados.
 */
final class CursorPaginator {

	/**
	 * Secreto para la firma del cursor.
	 *
	 * @var string
	 */
	private string $secret;

	/**
	 * Construye el paginador.
	 *
	 * @param string $secret Secreto de firma.
	 */
	public function __construct( string $secret ) {
		$this->secret = $secret;
	}

	/**
	 * Codifica un cursor a partir del ultimo elemento de la pagina.
	 *
	 * @param string $date Fecha del elemento en formato MySQL.
	 * @param int    $id   ID del elemento.
	 * @return string
	 */
	public function encode( string $date, int $id ): string {
		$payload   = wp_json_encode(
			array(
				'd' => $date,
				'i' => $id,
			)
		);
		$encoded   = $this->base64url( (string) $payload );
		$signature = $this->base64url( $this->sign( $encoded ) );

		return $encoded . '.' . $signature;
	}

	/**
	 * Decodifica y verifica un cursor.
	 *
	 * @param string $cursor Cursor recibido.
	 * @return array<string, mixed>|null Datos, o null si no es valido.
	 */
	public function decode( string $cursor ): ?array {
		$parts = explode( '.', $cursor );

		if ( 2 !== count( $parts ) ) {
			return null;
		}

		list( $encoded, $signature ) = $parts;

		if ( ! hash_equals( $this->sign( $encoded ), $this->unbase64url( $signature ) ) ) {
			return null;
		}

		$data = json_decode( $this->unbase64url( $encoded ), true );

		if ( ! is_array( $data ) || ! isset( $data['d'], $data['i'] ) ) {
			return null;
		}

		return array(
			'date' => (string) $data['d'],
			'id'   => (int) $data['i'],
		);
	}

	/**
	 * Traduce un cursor a la clausula WHERE keyset.
	 *
	 * Se compara la pareja (post_date, ID) para que el desempate sea estable:
	 * sin el ID, dos elementos con la misma fecha podrian repetirse o
	 * perderse entre paginas.
	 *
	 * @param array<string, mixed> $args   Argumentos de WP_Query.
	 * @param array<string, mixed> $cursor Cursor decodificado.
	 * @param string               $order  ASC o DESC.
	 * @return array<string, mixed>
	 */
	public function apply( array $args, array $cursor, string $order = 'DESC' ): array {
		$args['codeia_cursor'] = array(
			'date'  => $cursor['date'],
			'id'    => $cursor['id'],
			'order' => 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC',
		);

		// Sin cursor no hace falta el conteo total, que es lo caro.
		$args['no_found_rows'] = true;

		return $args;
	}

	/**
	 * Firma una cadena.
	 *
	 * @param string $input Entrada.
	 * @return string
	 */
	private function sign( string $input ): string {
		return hash_hmac( 'sha256', $input, $this->secret, true );
	}

	/**
	 * Codifica en base64url sin relleno.
	 *
	 * @param string $data Datos.
	 * @return string
	 */
	private function base64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Decodifica base64url.
	 *
	 * @param string $data Datos.
	 * @return string
	 */
	private function unbase64url( string $data ): string {
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true );

		return false === $decoded ? '' : $decoded;
	}

	/**
	 * Engancha la traduccion del cursor a SQL.
	 *
	 * Sin este filtro, apply() dejaria el argumento en la consulta y nadie lo
	 * leeria: la paginacion devolveria siempre la primera pagina.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'posts_where', array( $this, 'filter_where' ), 10, 2 );
	}

	/**
	 * Anade la condicion keyset a la clausula WHERE.
	 *
	 * Se compara la pareja (post_date_gmt, ID) para que el desempate sea
	 * estable: sin el ID, dos elementos con la misma fecha podrian repetirse
	 * entre paginas o perderse.
	 *
	 * @param string    $where Clausula WHERE actual.
	 * @param \WP_Query $query Consulta.
	 * @return string
	 */
	public function filter_where( string $where, $query ): string {
		if ( ! $query instanceof \WP_Query ) {
			return $where;
		}

		$cursor = $query->get( 'codeia_cursor' );

		if ( ! is_array( $cursor ) || ! isset( $cursor['date'], $cursor['id'] ) ) {
			return $where;
		}

		global $wpdb;

		$comparator = 'ASC' === ( $cursor['order'] ?? 'DESC' ) ? '>' : '<';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $comparator solo puede ser < o >, derivado de una comparacion literal; los valores si van por prepare().
		$where .= $wpdb->prepare(
			" AND ( {$wpdb->posts}.post_date_gmt {$comparator} %s"
			. " OR ( {$wpdb->posts}.post_date_gmt = %s AND {$wpdb->posts}.ID {$comparator} %d ) )",
			$cursor['date'],
			$cursor['date'],
			(int) $cursor['id']
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $where;
	}
}
