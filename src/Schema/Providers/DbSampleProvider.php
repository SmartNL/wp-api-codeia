<?php
/**
 * Nivel 3: muestreo de wp_postmeta.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Schema\Providers;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Schema\ExclusionList;
use WpApi\Codeia\Schema\FieldDefinition;
use WpApi\Codeia\Schema\TypeInferrer;

/**
 * Descubre campos consultando lo que hay realmente en la base de datos.
 *
 * Es el unico nivel EMPIRICO: los demas dicen que campos deberian existir,
 * este dice cuales existen. Es lo que hace funcionar el caso de
 * flavor-real-estate, cuyos 28 campos no pasan por register_meta().
 *
 * Tambien es el mas caro. NUNCA se ejecuta durante una peticion de API: solo
 * en la activacion, en un rebuild manual o en un cron de refresco.
 */
final class DbSampleProvider implements FieldProvider {

	/**
	 * Estados de post que se consideran al muestrear.
	 */
	private const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * Numero minimo de usos para que una clave se considere campo.
	 */
	private const MIN_USAGE = 2;

	/**
	 * Numero maximo de claves distintas que se recogen.
	 */
	private const MAX_KEYS = 200;

	/**
	 * Lista de exclusion.
	 *
	 * @var ExclusionList
	 */
	private ExclusionList $exclusions;

	/**
	 * Inferidor de tipos.
	 *
	 * @var TypeInferrer
	 */
	private TypeInferrer $inferrer;

	/**
	 * Construye el proveedor.
	 *
	 * @param ExclusionList $exclusions Lista de exclusion.
	 * @param TypeInferrer  $inferrer   Inferidor de tipos.
	 */
	public function __construct( ExclusionList $exclusions, TypeInferrer $inferrer ) {
		$this->exclusions = $exclusions;
		$this->inferrer   = $inferrer;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return FieldDefinition::ORIGIN_DB_SAMPLE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function confidence(): int {
		return FieldDefinition::CONFIDENCE[ FieldDefinition::ORIGIN_DB_SAMPLE ];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type.
	 * @return FieldDefinition[]
	 */
	public function fields_for( string $post_type ): array {
		$usage = $this->meta_keys_for( $post_type );

		if ( array() === $usage ) {
			return array();
		}

		$fields = array();

		foreach ( $usage as $key => $count ) {
			$values = $this->sample_values( $post_type, $key );
			$sample = isset( $values[0] ) ? (string) $values[0] : null;

			if ( $this->exclusions->excludes( $key, $usage, $sample ) ) {
				continue;
			}

			$inferred = $this->inferrer->infer( $values );

			$fields[] = new FieldDefinition(
				$key,
				$key,
				(string) $inferred['type'],
				FieldDefinition::ORIGIN_DB_SAMPLE,
				array(
					'format'      => $inferred['format'],
					'ambiguous'   => (bool) $inferred['ambiguous'],
					'enum'        => (array) $inferred['enum'],
					'protected'   => is_protected_meta( $key, 'post' ),
					'usage_count' => $count,
					'relation'    => $this->propose_relation( $key, $values, (string) $inferred['type'] ),
				)
			);
		}

		return $fields;
	}

	/**
	 * Recoge las claves meta usadas por un post type y su numero de usos.
	 *
	 * El INNER JOIN con wp_posts es obligatorio: wp_postmeta no sabe de post
	 * types. Es la parte cara de la consulta.
	 *
	 * @param string $post_type Post type.
	 * @return array<string, int> Clave meta => numero de usos.
	 */
	private function meta_keys_for( string $post_type ): array {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( self::STATUSES ), '%s' ) );

		/*
		 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		 *
		 * $placeholders es la cadena "%s, %s, ..." generada a partir de
		 * self::STATUSES, una constante privada de esta clase. No hay ninguna
		 * via por la que entrada externa llegue ahi: es el idioma estandar de
		 * WordPress para una clausula IN con numero variable de valores, y los
		 * valores en si SI van por prepare().
		 */
		$sql = $wpdb->prepare(
			"SELECT pm.meta_key AS meta_key, COUNT(*) AS usos
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = %s
			   AND p.post_status IN ({$placeholders})
			 GROUP BY pm.meta_key
			 HAVING usos >= %d
			 ORDER BY usos DESC
			 LIMIT %d",
			array_merge(
				array( $post_type ),
				self::STATUSES,
				array( self::MIN_USAGE, self::MAX_KEYS )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql ya viene de prepare().
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$usage = array();

		foreach ( $rows as $row ) {
			$usage[ (string) $row['meta_key'] ] = (int) $row['usos'];
		}

		return $usage;
	}

	/**
	 * Toma una muestra de valores distintos de una clave.
	 *
	 * @param string $post_type Post type.
	 * @param string $meta_key  Clave meta.
	 * @return array<int, string>
	 */
	private function sample_values( string $post_type, string $meta_key ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders es %s repetido, derivado de self::STATUSES.
		$sql = $wpdb->prepare(
			"SELECT DISTINCT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = %s
               AND pm.meta_key = %s
               AND pm.meta_value <> ''
             LIMIT %d",
			$post_type,
			$meta_key,
			TypeInferrer::SAMPLE_SIZE
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql ya viene de prepare().
		$values = $wpdb->get_col( $sql );

		return is_array( $values ) ? array_map( 'strval', $values ) : array();
	}

	/**
	 * Propone una relacion si la clave parece una referencia a otro post.
	 *
	 * Se exigen las dos senales: el nombre sugiere referencia (sufijo _id o
	 * _ids) y los valores resuelven a posts existentes de un mismo tipo.
	 * Nunca se activa sola: una relacion mal inferida genera expansiones y
	 * consultas erroneas, asi que queda pendiente de confirmacion manual.
	 *
	 * @param string             $key    Clave meta.
	 * @param array<int, string> $values Valores de muestra.
	 * @param string             $type   Tipo inferido.
	 * @return array<string, mixed>|null
	 */
	private function propose_relation( string $key, array $values, string $type ): ?array {
		if ( FieldDefinition::TYPE_INTEGER !== $type ) {
			return null;
		}

		if ( 1 !== preg_match( '/_ids?$/', $key ) ) {
			return null;
		}

		if ( array() === $values ) {
			return null;
		}

		$types = array();

		foreach ( array_slice( $values, 0, 10 ) as $value ) {
			$related = get_post_type( (int) $value );

			if ( false === $related ) {
				return null;
			}

			$types[ $related ] = true;
		}

		if ( 1 !== count( $types ) ) {
			return null;
		}

		return array(
			'post_type' => (string) array_key_first( $types ),
			'confirmed' => false,
		);
	}

	/**
	 * Motivos por los que se descarto cada clave.
	 *
	 * Se muestran en el panel de estado: por que no aparece un campo sera la
	 * pregunta mas frecuente que genere este plugin.
	 *
	 * @return array<string, string>
	 */
	public function exclusion_reasons(): array {
		return $this->exclusions->reasons();
	}
}
