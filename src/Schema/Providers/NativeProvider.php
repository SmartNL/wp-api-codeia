<?php
/**
 * Nivel 1: registro nativo de WordPress.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Schema\Providers;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Schema\FieldDefinition;

/**
 * Lee la meta declarada con register_meta() y los campos REST adicionales.
 *
 * Es la fuente autoritativa cuando existe: la declaro quien escribio el
 * codigo. El problema es que register_meta() es OPCIONAL y buena parte del
 * ecosistema no lo usa, asi que en la practica su cobertura es baja. En la
 * instalacion de referencia devuelve vacio para el post type property, cuyos
 * 28 campos se guardan con update_post_meta() sin registro previo.
 */
final class NativeProvider implements FieldProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return FieldDefinition::ORIGIN_NATIVE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return function_exists( 'get_registered_meta_keys' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function confidence(): int {
		return FieldDefinition::CONFIDENCE[ FieldDefinition::ORIGIN_NATIVE ];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type.
	 * @return FieldDefinition[]
	 */
	public function fields_for( string $post_type ): array {
		$fields = array();

		foreach ( get_registered_meta_keys( 'post', $post_type ) as $key => $args ) {
			$fields[] = new FieldDefinition(
				(string) $key,
				(string) $key,
				$this->map_type( (string) ( $args['type'] ?? 'string' ) ),
				FieldDefinition::ORIGIN_NATIVE,
				array(
					'single'    => (bool) ( $args['single'] ?? true ),
					'label'     => isset( $args['description'] ) && '' !== $args['description']
						? (string) $args['description']
						: null,
					'protected' => is_protected_meta( (string) $key, 'post' ),
				)
			);
		}

		return array_merge( $fields, $this->rest_additional_fields( $post_type ) );
	}

	/**
	 * Recoge los campos anadidos con register_rest_field().
	 *
	 * No son meta, pero forman parte de la representacion REST del recurso y
	 * deben aparecer en el catalogo.
	 *
	 * @param string $post_type Post type.
	 * @return FieldDefinition[]
	 */
	private function rest_additional_fields( string $post_type ): array {
		global $wp_rest_additional_fields;

		if ( ! is_array( $wp_rest_additional_fields ) || ! isset( $wp_rest_additional_fields[ $post_type ] ) ) {
			return array();
		}

		$fields = array();

		foreach ( (array) $wp_rest_additional_fields[ $post_type ] as $name => $args ) {
			$schema = isset( $args['schema'] ) && is_array( $args['schema'] ) ? $args['schema'] : array();

			$fields[] = new FieldDefinition(
				(string) $name,
				(string) $name,
				$this->map_type( (string) ( $schema['type'] ?? 'string' ) ),
				FieldDefinition::ORIGIN_NATIVE,
				array(
					'label'  => isset( $schema['description'] ) ? (string) $schema['description'] : null,
					'format' => isset( $schema['format'] ) ? (string) $schema['format'] : null,
				)
			);
		}

		return $fields;
	}

	/**
	 * Traduce el tipo declarado por WordPress al vocabulario del plugin.
	 *
	 * @param string $type Tipo declarado.
	 * @return string
	 */
	private function map_type( string $type ): string {
		$map = array(
			'integer' => FieldDefinition::TYPE_INTEGER,
			'number'  => FieldDefinition::TYPE_NUMBER,
			'boolean' => FieldDefinition::TYPE_BOOLEAN,
			'array'   => FieldDefinition::TYPE_ARRAY,
			'object'  => FieldDefinition::TYPE_OBJECT,
			'string'  => FieldDefinition::TYPE_STRING,
		);

		return $map[ strtolower( $type ) ] ?? FieldDefinition::TYPE_STRING;
	}
}
