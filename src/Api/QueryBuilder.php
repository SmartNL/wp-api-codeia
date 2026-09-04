<?php
/**
 * Traduccion de filtros a argumentos de WP_Query.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Api;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WpApi\Codeia\Schema\FieldDefinition;
use WpApi\Codeia\Schema\ResourceDefinition;

/**
 * Convierte los parametros de la peticion en una consulta segura.
 *
 * Todo identificador que llega del cliente —orderby, nombres de campo— se
 * traduce a traves del esquema. Si la traduccion no encuentra nada, la
 * peticion se rechaza: el valor del cliente NUNCA llega a la consulta.
 *
 * $wpdb->prepare() protege valores, no identificadores; un meta_key sin
 * validar permitiria leer cualquier clave de la instalacion, incluidas las
 * internas y las de otros plugins.
 */
final class QueryBuilder {

	/**
	 * Operadores admitidos y su traduccion a meta_query.
	 */
	private const OPERATORS = array(
		'gt'         => '>',
		'gte'        => '>=',
		'lt'         => '<',
		'lte'        => '<=',
		'like'       => 'LIKE',
		'in'         => 'IN',
		'not_in'     => 'NOT IN',
		'between'    => 'BETWEEN',
		'exists'     => 'EXISTS',
		'not_exists' => 'NOT EXISTS',
	);

	/**
	 * Campos nativos que se traducen a argumentos directos de WP_Query.
	 */
	private const NATIVE_ARGS = array(
		'status' => 'post_status',
		'author' => 'author',
		'parent' => 'post_parent',
		'search' => 's',
	);

	/**
	 * Maximo de clausulas meta.
	 *
	 * @var int
	 */
	private int $max_meta_clauses;

	/**
	 * Construye el constructor de consultas.
	 *
	 * @param int $max_meta_clauses Maximo de clausulas meta.
	 */
	public function __construct( int $max_meta_clauses = 4 ) {
		$this->max_meta_clauses = max( 1, $max_meta_clauses );
	}

	/**
	 * Traduce los filtros de la peticion.
	 *
	 * @param ResourceDefinition   $definition Definicion del recurso.
	 * @param array<string, mixed> $filters    Filtros recibidos.
	 * @return array<string, mixed>|WP_Error
	 */
	public function build( ResourceDefinition $definition, array $filters ) {
		$args       = array();
		$meta_query = array();

		foreach ( $filters as $name => $criteria ) {
			$name = (string) $name;

			if ( isset( self::NATIVE_ARGS[ $name ] ) ) {
				$args[ self::NATIVE_ARGS[ $name ] ] = $this->scalar( $criteria );
				continue;
			}

			if ( in_array( $name, $definition->taxonomies, true ) ) {
				$args['tax_query'][] = $this->tax_clause( $name, $criteria );
				continue;
			}

			$storage_key = $definition->storage_key_for( $name );

			if ( null === $storage_key ) {
				return ErrorFormatter::unknown_field( $name );
			}

			$field = $definition->field( $storage_key );

			if ( null === $field ) {
				return ErrorFormatter::unknown_field( $name );
			}

			$clause = $this->meta_clause( $field, $criteria );

			if ( is_wp_error( $clause ) ) {
				return $clause;
			}

			$meta_query[] = $clause;
		}

		if ( count( $meta_query ) > $this->max_meta_clauses ) {
			return ErrorFormatter::query_too_complex( $this->max_meta_clauses );
		}

		if ( array() !== $meta_query ) {
			$meta_query['relation'] = 'AND';
			$args['meta_query']     = $meta_query;
		}

		return $args;
	}

	/**
	 * Compone una clausula de meta_query.
	 *
	 * El type NO es opcional: meta_value es LONGTEXT, y sin
	 * 'type' => 'NUMERIC' MySQL compara como cadena, con lo que
	 * "300000" < "89000" resulta verdadero. El tipo sale del esquema, que es
	 * justo por lo que la deteccion de tipos del sprint 2 no era un adorno.
	 *
	 * @param FieldDefinition $field    Campo.
	 * @param mixed           $criteria Criterio recibido.
	 * @return array<string, mixed>|WP_Error
	 */
	private function meta_clause( FieldDefinition $field, $criteria ) {
		$clause = array(
			'key'  => $field->storage_key,
			'type' => $this->meta_type( $field ),
		);

		if ( ! is_array( $criteria ) ) {
			$value = $this->scalar( $criteria );

			if ( is_string( $value ) && str_contains( $value, ',' ) ) {
				$clause['compare'] = 'IN';
				$clause['value']   = array_map( 'trim', explode( ',', $value ) );

				return $clause;
			}

			$clause['compare'] = '=';
			$clause['value']   = $value;

			return $clause;
		}

		$operator = (string) array_key_first( $criteria );

		if ( ! isset( self::OPERATORS[ $operator ] ) ) {
			return ErrorFormatter::not_filterable( $field->exposed_name );
		}

		$clause['compare'] = self::OPERATORS[ $operator ];
		$raw               = $criteria[ $operator ];

		if ( in_array( $operator, array( 'exists', 'not_exists' ), true ) ) {
			unset( $clause['type'] );

			return $clause;
		}

		if ( in_array( $operator, array( 'in', 'not_in', 'between' ), true ) ) {
			$clause['value'] = is_array( $raw )
				? array_map( array( $this, 'scalar' ), $raw )
				: array_map( 'trim', explode( ',', (string) $raw ) );

			return $clause;
		}

		$clause['value'] = $this->scalar( $raw );

		return $clause;
	}

	/**
	 * Tipo de comparacion de MySQL para un campo.
	 *
	 * @param FieldDefinition $field Campo.
	 * @return string
	 */
	private function meta_type( FieldDefinition $field ): string {
		switch ( $field->type ) {
			case FieldDefinition::TYPE_INTEGER:
			case FieldDefinition::TYPE_BOOLEAN:
				return 'NUMERIC';

			case FieldDefinition::TYPE_NUMBER:
				return 'DECIMAL(20,6)';

			default:
				return 'date-time' === $field->format ? 'DATETIME' : 'CHAR';
		}
	}

	/**
	 * Compone una clausula de taxonomia.
	 *
	 * @param string $taxonomy Taxonomia.
	 * @param mixed  $criteria Criterio.
	 * @return array<string, mixed>
	 */
	private function tax_clause( string $taxonomy, $criteria ): array {
		$terms = is_array( $criteria )
			? array_map( array( $this, 'scalar' ), $criteria )
			: array_map( 'trim', explode( ',', (string) $criteria ) );

		return array(
			'taxonomy' => $taxonomy,
			'field'    => 'slug',
			'terms'    => $terms,
			'operator' => 'IN',
		);
	}

	/**
	 * Sanea un valor escalar entrante.
	 *
	 * @param mixed $value Valor.
	 * @return mixed
	 */
	private function scalar( $value ) {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Traduce el parametro orderby a argumentos de WP_Query.
	 *
	 * El valor NUNCA se pasa sin validar contra la lista blanca: orderby
	 * acaba en la clausula ORDER BY y es un vector clasico de inyeccion.
	 *
	 * @param ResourceDefinition $definition Definicion del recurso.
	 * @param string             $orderby    Campo solicitado.
	 * @param string             $order      ASC o DESC.
	 * @return array<string, mixed>|WP_Error
	 */
	public function order( ResourceDefinition $definition, string $orderby, string $order ) {
		$order  = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$native = array( 'date', 'title', 'id', 'modified', 'menu_order', 'author' );

		if ( in_array( strtolower( $orderby ), $native, true ) ) {
			// Desempate por ID siempre: sin el, dos elementos con el mismo
			// valor pueden alternar entre peticiones y duplicarse al paginar.
			return array(
				'orderby' => array(
					strtolower( $orderby ) => $order,
					'ID'                   => $order,
				),
			);
		}

		$storage_key = $definition->storage_key_for( $orderby );

		if ( null === $storage_key ) {
			return ErrorFormatter::unknown_field( $orderby );
		}

		$field = $definition->field( $storage_key );

		if ( null === $field ) {
			return ErrorFormatter::unknown_field( $orderby );
		}

		$numeric = in_array(
			$field->type,
			array( FieldDefinition::TYPE_INTEGER, FieldDefinition::TYPE_NUMBER ),
			true
		);

		/*
		 * Se usa meta_query nombrada con EXISTS en lugar de meta_key directo.
		 * Con meta_key, WP_Query hace INNER JOIN y las entradas SIN esa clave
		 * desaparecen del listado sin explicacion aparente.
		 */
		return array(
			'meta_query' => array(
				'codeia_order' => array(
					'key'     => $storage_key,
					'compare' => 'EXISTS',
				),
			),
			'orderby'    => array(
				'codeia_order' => $order,
				'ID'           => $order,
			),
			'meta_type'  => $numeric ? 'NUMERIC' : 'CHAR',
		);
	}
}
