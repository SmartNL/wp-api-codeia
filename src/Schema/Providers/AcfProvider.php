<?php
/**
 * Nivel 2: adaptador de Advanced Custom Fields.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Schema\Providers;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Schema\FieldDefinition;

/**
 * Extrae las definiciones de campo declaradas en ACF.
 *
 * ACF es una API de terceros y cambia entre versiones mayores. Si la funcion
 * de entrada no existe, el adaptador se desactiva solo: un adaptador roto
 * nunca debe impedir que el resto del esquema se construya.
 */
final class AcfProvider implements FieldProvider {

	/**
	 * Correspondencia entre tipos de ACF y tipos JSON Schema.
	 */
	private const TYPE_MAP = array(
		'number'           => FieldDefinition::TYPE_NUMBER,
		'range'            => FieldDefinition::TYPE_NUMBER,
		'true_false'       => FieldDefinition::TYPE_BOOLEAN,
		'checkbox'         => FieldDefinition::TYPE_ARRAY,
		'gallery'          => FieldDefinition::TYPE_ARRAY,
		'relationship'     => FieldDefinition::TYPE_ARRAY,
		'repeater'         => FieldDefinition::TYPE_ARRAY,
		'group'            => FieldDefinition::TYPE_OBJECT,
		'flexible_content' => FieldDefinition::TYPE_ARRAY,
		'image'            => FieldDefinition::TYPE_INTEGER,
		'file'             => FieldDefinition::TYPE_INTEGER,
		'post_object'      => FieldDefinition::TYPE_INTEGER,
		'user'             => FieldDefinition::TYPE_INTEGER,
	);

	/**
	 * Formatos derivados del tipo de ACF.
	 */
	private const FORMAT_MAP = array(
		'email'            => 'email',
		'url'              => 'uri',
		'date_picker'      => 'date-time',
		'date_time_picker' => 'date-time',
	);

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return FieldDefinition::ORIGIN_ACF;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function confidence(): int {
		return FieldDefinition::CONFIDENCE[ FieldDefinition::ORIGIN_ACF ];
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

		$groups = acf_get_field_groups( array( 'post_type' => $post_type ) );

		if ( ! is_array( $groups ) ) {
			return array();
		}

		$fields = array();

		foreach ( $groups as $group ) {
			$group_fields = acf_get_fields( $group );

			if ( ! is_array( $group_fields ) ) {
				continue;
			}

			foreach ( $group_fields as $field ) {
				$definition = $this->to_definition( $field );

				if ( null !== $definition ) {
					$fields[] = $definition;
				}
			}
		}

		return $fields;
	}

	/**
	 * Traduce un campo de ACF al modelo del plugin.
	 *
	 * @param array<string, mixed> $field Campo de ACF.
	 * @return FieldDefinition|null
	 */
	private function to_definition( array $field ): ?FieldDefinition {
		$name = isset( $field['name'] ) ? (string) $field['name'] : '';

		if ( '' === $name ) {
			return null;
		}

		$acf_type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

		return new FieldDefinition(
			$name,
			$name,
			self::TYPE_MAP[ $acf_type ] ?? FieldDefinition::TYPE_STRING,
			FieldDefinition::ORIGIN_ACF,
			array(
				'label'  => isset( $field['label'] ) ? (string) $field['label'] : null,
				'format' => self::FORMAT_MAP[ $acf_type ] ?? null,
				'enum'   => $this->choices( $field ),
			)
		);
	}

	/**
	 * Extrae las opciones de un campo de seleccion.
	 *
	 * @param array<string, mixed> $field Campo de ACF.
	 * @return array<int, scalar>
	 */
	private function choices( array $field ): array {
		if ( ! isset( $field['choices'] ) || ! is_array( $field['choices'] ) ) {
			return array();
		}

		return array_map( 'strval', array_keys( $field['choices'] ) );
	}
}
