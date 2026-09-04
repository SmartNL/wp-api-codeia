<?php
/**
 * Definicion completa de un recurso expuesto.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Objeto pivote del que derivan rutas, esquema OpenAPI y validacion.
 *
 * Que las tres cosas salgan del mismo sitio es lo que garantiza que la
 * documentacion no se desincronice de la implementacion.
 */
final class ResourceDefinition {

	/**
	 * Post type.
	 *
	 * @var string
	 */
	public string $post_type;

	/**
	 * Etiqueta legible.
	 *
	 * @var string
	 */
	public string $label;

	/**
	 * Si el post type es jerarquico.
	 *
	 * @var bool
	 */
	public bool $hierarchical;

	/**
	 * Caracteristicas declaradas por el post type.
	 *
	 * @var string[]
	 */
	public array $supports;

	/**
	 * Taxonomias asociadas.
	 *
	 * @var string[]
	 */
	public array $taxonomies;

	/**
	 * Campos detectados, indexados por clave de almacenamiento.
	 *
	 * @var FieldDefinition[]
	 */
	public array $fields;

	/**
	 * Conflictos sin resolver.
	 *
	 * @var array<string, mixed>
	 */
	public array $conflicts;

	/**
	 * Construye la definicion.
	 *
	 * @param string               $post_type Post type.
	 * @param array<string, mixed> $meta      Metadatos del recurso.
	 * @param FieldDefinition[]    $fields    Campos detectados.
	 * @param array<string, mixed> $conflicts Conflictos detectados.
	 */
	public function __construct( string $post_type, array $meta, array $fields, array $conflicts = array() ) {
		$this->post_type    = $post_type;
		$this->label        = (string) ( $meta['label'] ?? $post_type );
		$this->hierarchical = (bool) ( $meta['hierarchical'] ?? false );
		$this->supports     = array_values( (array) ( $meta['supports'] ?? array() ) );
		$this->taxonomies   = array_values( (array) ( $meta['taxonomies'] ?? array() ) );
		$this->fields       = $fields;
		$this->conflicts    = $conflicts;
	}

	/**
	 * Traduce un nombre publico a su clave de almacenamiento.
	 *
	 * Devuelve null si el nombre no existe. El motor de consultas usa esto
	 * como lista blanca: un identificador que no traduce se rechaza en lugar
	 * de llegar a la consulta.
	 *
	 * @param string $exposed_name Nombre publico.
	 * @return string|null
	 */
	public function storage_key_for( string $exposed_name ): ?string {
		foreach ( $this->fields as $field ) {
			if ( $field->exposed_name === $exposed_name ) {
				return $field->storage_key;
			}
		}

		return null;
	}

	/**
	 * Devuelve un campo por su clave de almacenamiento.
	 *
	 * @param string $storage_key Clave.
	 * @return FieldDefinition|null
	 */
	public function field( string $storage_key ): ?FieldDefinition {
		return $this->fields[ $storage_key ] ?? null;
	}

	/**
	 * Campos cuyo tipo procede de una conjetura.
	 *
	 * @return FieldDefinition[]
	 */
	public function inferred_fields(): array {
		return array_filter(
			$this->fields,
			static function ( FieldDefinition $field ): bool {
				return $field->is_inferred();
			}
		);
	}

	/**
	 * Campos con una relacion propuesta sin confirmar.
	 *
	 * @return FieldDefinition[]
	 */
	public function pending_relations(): array {
		return array_filter(
			$this->fields,
			static function ( FieldDefinition $field ): bool {
				return $field->has_unconfirmed_relation();
			}
		);
	}

	/**
	 * Serializa la definicion para cachearla.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$fields = array();

		foreach ( $this->fields as $key => $field ) {
			$fields[ $key ] = $field->to_array();
		}

		return array(
			'post_type'    => $this->post_type,
			'label'        => $this->label,
			'hierarchical' => $this->hierarchical,
			'supports'     => $this->supports,
			'taxonomies'   => $this->taxonomies,
			'fields'       => $fields,
			'conflicts'    => $this->conflicts,
		);
	}

	/**
	 * Reconstruye una definicion cacheada.
	 *
	 * @param array<string, mixed> $data Datos de to_array().
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$fields = array();

		foreach ( (array) ( $data['fields'] ?? array() ) as $key => $field ) {
			$fields[ (string) $key ] = FieldDefinition::from_array( (array) $field );
		}

		return new self(
			(string) ( $data['post_type'] ?? '' ),
			$data,
			$fields,
			(array) ( $data['conflicts'] ?? array() )
		);
	}
}
