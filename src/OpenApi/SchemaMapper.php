<?php
/**
 * Conversion de JSON Schema draft-04 a OpenAPI 3.1.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\OpenApi;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Schema\FieldDefinition;

/**
 * Traduce el vocabulario del esquema al de OpenAPI.
 *
 * Los esquemas de WordPress siguen JSON Schema draft-04; OpenAPI 3.1 usa
 * 2020-12. No son identicos, y la conversion NO es cosmetica:
 * exclusiveMinimum mal traducido cambia la semantica de la validacion.
 */
final class SchemaMapper {

	/**
	 * Traduce un campo del esquema a una propiedad OpenAPI.
	 *
	 * @param FieldDefinition $field Campo.
	 * @return array<string, mixed>
	 */
	public function field_to_property( FieldDefinition $field ): array {
		$property = array( 'type' => $this->map_type( $field->type ) );

		if ( null !== $field->format ) {
			$property['format'] = $field->format;
		}

		if ( array() !== $field->enum ) {
			$property['enum'] = array_values( $field->enum );
		}

		if ( null !== $field->label && '' !== $field->label ) {
			$property['description'] = $field->label;
		}

		if ( FieldDefinition::TYPE_ARRAY === $field->type ) {
			$property['items'] = array( 'type' => 'string' );
		}

		if ( null !== $field->relation ) {
			$property['x-codeia-relation'] = $field->relation;
		}

		if ( $field->is_inferred() ) {
			/*
			 * Publicar un tipo inferido como si fuera declarado es peor que no
			 * documentarlo: un consumidor generaria un cliente tipado sobre
			 * una conjetura. Las extensiones x- son validas en OpenAPI y las
			 * herramientas las ignoran sin fallar.
			 */
			$property['x-codeia-confidence'] = $field->confidence;
			$property['x-codeia-origin']     = $field->origin;
			$property['description']         = ( $property['description'] ?? '' )
				. ' (tipo inferido del contenido de la base de datos)';
		}

		return $property;
	}

	/**
	 * Convierte un esquema draft-04 al vocabulario 2020-12.
	 *
	 * @param array<string, mixed> $schema Esquema draft-04.
	 * @return array<string, mixed>
	 */
	public function to_openapi( array $schema ): array {
		unset( $schema['$schema'] );

		if ( isset( $schema['id'] ) ) {
			$schema['$id'] = $schema['id'];
			unset( $schema['id'] );
		}

		if ( isset( $schema['definitions'] ) ) {
			$schema['$defs'] = $schema['definitions'];
			unset( $schema['definitions'] );
		}

		$schema = $this->convert_exclusive_bounds( $schema );

		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as $name => $property ) {
				if ( is_array( $property ) ) {
					$schema['properties'][ $name ] = $this->to_openapi( $property );
				}
			}
		}

		return $schema;
	}

	/**
	 * Traduce los limites exclusivos de draft-04 a 2020-12.
	 *
	 * En draft-04, exclusiveMinimum es un booleano que acompana a minimum.
	 * En 2020-12 es el propio valor. Traducirlo mal cambia la semantica del
	 * limite sin que nadie lo note hasta que una validacion falla.
	 *
	 * @param array<string, mixed> $schema Esquema.
	 * @return array<string, mixed>
	 */
	private function convert_exclusive_bounds( array $schema ): array {
		if ( isset( $schema['exclusiveMinimum'] ) && is_bool( $schema['exclusiveMinimum'] ) ) {
			if ( $schema['exclusiveMinimum'] && isset( $schema['minimum'] ) ) {
				$schema['exclusiveMinimum'] = $schema['minimum'];
				unset( $schema['minimum'] );
			} else {
				unset( $schema['exclusiveMinimum'] );
			}
		}

		if ( isset( $schema['exclusiveMaximum'] ) && is_bool( $schema['exclusiveMaximum'] ) ) {
			if ( $schema['exclusiveMaximum'] && isset( $schema['maximum'] ) ) {
				$schema['exclusiveMaximum'] = $schema['maximum'];
				unset( $schema['maximum'] );
			} else {
				unset( $schema['exclusiveMaximum'] );
			}
		}

		return $schema;
	}

	/**
	 * Traduce el tipo interno al de OpenAPI.
	 *
	 * @param string $type Tipo del esquema.
	 * @return string
	 */
	private function map_type( string $type ): string {
		$map = array(
			FieldDefinition::TYPE_INTEGER => 'integer',
			FieldDefinition::TYPE_NUMBER  => 'number',
			FieldDefinition::TYPE_BOOLEAN => 'boolean',
			FieldDefinition::TYPE_ARRAY   => 'array',
			FieldDefinition::TYPE_OBJECT  => 'object',
			FieldDefinition::TYPE_STRING  => 'string',
		);

		return $map[ $type ] ?? 'string';
	}
}
