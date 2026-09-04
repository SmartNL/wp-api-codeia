<?php
/**
 * Nivel 2: adaptador de JetEngine.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Schema\Providers;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Schema\FieldDefinition;

/**
 * Extrae las definiciones de campo declaradas en JetEngine.
 *
 * JetEngine guarda sus meta boxes en su propia estructura interna. Igual que
 * los demas adaptadores, se desactiva solo si la API no responde como se
 * espera, en lugar de romper la construccion del esquema.
 */
final class JetEngineProvider implements FieldProvider {

	/**
	 * Correspondencia entre tipos de JetEngine y tipos JSON Schema.
	 */
	private const TYPE_MAP = array(
		'number'   => FieldDefinition::TYPE_NUMBER,
		'checkbox' => FieldDefinition::TYPE_ARRAY,
		'switcher' => FieldDefinition::TYPE_BOOLEAN,
		'media'    => FieldDefinition::TYPE_INTEGER,
		'gallery'  => FieldDefinition::TYPE_ARRAY,
		'repeater' => FieldDefinition::TYPE_ARRAY,
		'posts'    => FieldDefinition::TYPE_ARRAY,
		'date'     => FieldDefinition::TYPE_STRING,
	);

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return FieldDefinition::ORIGIN_JETENGINE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return function_exists( 'jet_engine' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function confidence(): int {
		return FieldDefinition::CONFIDENCE[ FieldDefinition::ORIGIN_JETENGINE ];
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

		$engine = jet_engine();

		if ( ! is_object( $engine ) || ! isset( $engine->meta_boxes ) ) {
			return array();
		}

		$boxes = $engine->meta_boxes;

		if ( ! is_object( $boxes ) || ! method_exists( $boxes, 'get_registered_fields' ) ) {
			return array();
		}

		$registered = $boxes->get_registered_fields( 'post', $post_type );

		if ( ! is_array( $registered ) ) {
			return array();
		}

		$fields = array();

		foreach ( $registered as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$name = isset( $field['name'] ) ? (string) $field['name'] : '';

			if ( '' === $name ) {
				continue;
			}

			$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

			$fields[] = new FieldDefinition(
				$name,
				$name,
				self::TYPE_MAP[ $type ] ?? FieldDefinition::TYPE_STRING,
				FieldDefinition::ORIGIN_JETENGINE,
				array(
					'label'  => isset( $field['title'] ) ? (string) $field['title'] : null,
					'format' => 'date' === $type ? 'date-time' : null,
				)
			);
		}

		return $fields;
	}
}
