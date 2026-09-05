<?php
/**
 * Nivel 2: adaptador de Meta Box.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Schema\Providers;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Schema\FieldDefinition;

/**
 * Extrae las definiciones de campo declaradas en Meta Box.
 *
 * Meta Box mantiene un registro global de campos por tipo de objeto. Como el
 * resto de adaptadores, se desactiva solo si la API no esta disponible.
 */
final class MetaBoxProvider implements FieldProvider {

	/**
	 * Correspondencia entre tipos de Meta Box y tipos JSON Schema.
	 */
	private const TYPE_MAP = array(
		'number'   => FieldDefinition::TYPE_NUMBER,
		'range'    => FieldDefinition::TYPE_NUMBER,
		'slider'   => FieldDefinition::TYPE_NUMBER,
		'checkbox' => FieldDefinition::TYPE_BOOLEAN,
		'switch'   => FieldDefinition::TYPE_BOOLEAN,
		'image'    => FieldDefinition::TYPE_INTEGER,
		'file'     => FieldDefinition::TYPE_INTEGER,
		'post'     => FieldDefinition::TYPE_INTEGER,
		'group'    => FieldDefinition::TYPE_OBJECT,
	);

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return FieldDefinition::ORIGIN_METABOX;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return function_exists( 'rwmb_get_registry' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function confidence(): int {
		return FieldDefinition::CONFIDENCE[ FieldDefinition::ORIGIN_METABOX ];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type.
	 * @return FieldDefinition[]
	 */
	public function fields_for( string $post_type ): array {
		if ( ! $this->is_available() ) {
			return array();
		}

		$registry = rwmb_get_registry( 'field' );

		if ( ! is_object( $registry ) || ! method_exists( $registry, 'get_by_object_type' ) ) {
			return array();
		}

		$groups = $registry->get_by_object_type( 'post' );

		if ( ! is_array( $groups ) || ! isset( $groups[ $post_type ] ) ) {
			return array();
		}

		$fields = array();

		foreach ( (array) $groups[ $post_type ] as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$definition = $this->to_definition( $field );

			if ( null !== $definition ) {
				$fields[] = $definition;
			}
		}

		return $fields;
	}

	/**
	 * Traduce un campo de Meta Box al modelo del plugin.
	 *
	 * @param array<string, mixed> $field Campo de Meta Box.
	 * @return FieldDefinition|null
	 */
	private function to_definition( array $field ): ?FieldDefinition {
		$id = isset( $field['id'] ) ? (string) $field['id'] : '';

		if ( '' === $id ) {
			return null;
		}

		$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

		return new FieldDefinition(
			$id,
			$id,
			self::TYPE_MAP[ $type ] ?? FieldDefinition::TYPE_STRING,
			FieldDefinition::ORIGIN_METABOX,
			array(
				'label'  => isset( $field['name'] ) ? (string) $field['name'] : null,
				'single' => empty( $field['multiple'] ),
				'enum'   => isset( $field['options'] ) && is_array( $field['options'] )
					? array_map( 'strval', array_keys( $field['options'] ) )
					: array(),
			)
		);
	}
}
