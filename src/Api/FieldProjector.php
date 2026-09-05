<?php
/**
 * Proyeccion de campos en la respuesta.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Api;

defined( 'ABSPATH' ) || exit;

use WP_Post;
use WpApi\Codeia\Permissions\FieldVisibility;
use WpApi\Codeia\Schema\FieldDefinition;
use WpApi\Codeia\Schema\ResourceDefinition;

/**
 * Compone la representacion de un elemento aplicando los tres filtros.
 *
 * Un campo llega a la respuesta solo si supera los tres, EN ESTE ORDEN:
 *
 *   1. Esta expuesto en la configuracion.
 *   2. Es visible para el rol.
 *   3. El cliente lo pidio con _fields.
 *
 * El orden no es intercambiable: _fields nunca puede AMPLIAR lo que los dos
 * primeros decidieron. Es una herramienta para pedir menos, jamas para pedir
 * mas. Implementarlo al reves acaba filtrando campos vetados.
 */
final class FieldProjector {

	/**
	 * Campos nativos que siempre se consideran.
	 */
	private const NATIVE = array( 'id', 'title', 'status', 'date', 'modified', 'slug', 'link', 'author' );

	/**
	 * Servicio de visibilidad por campo.
	 *
	 * @var FieldVisibility
	 */
	private FieldVisibility $visibility;

	/**
	 * Construye el proyector.
	 *
	 * @param FieldVisibility $visibility Visibilidad por campo.
	 */
	public function __construct( FieldVisibility $visibility ) {
		$this->visibility = $visibility;
	}

	/**
	 * Compone la representacion de un post.
	 *
	 * @param WP_Post            $post       Post.
	 * @param ResourceDefinition $definition Definicion del recurso.
	 * @param int                $user_id    Usuario que pregunta.
	 * @param string[]           $requested  Campos pedidos con _fields.
	 * @return array<string, mixed>
	 */
	public function project( WP_Post $post, ResourceDefinition $definition, int $user_id, array $requested = array() ): array {
		$item = $this->native_fields( $post );

		foreach ( $definition->fields as $field ) {
			$item[ $field->exposed_name ] = $this->meta_value( $post->ID, $field );
		}

		// Filtro 2: visibilidad por rol. Los vetados se OMITEN.
		$item = $this->visibility->project( $item, $user_id, $definition->post_type );

		// Filtro 3: _fields solo puede reducir.
		if ( array() !== $requested ) {
			$item = array_intersect_key( $item, array_flip( $requested ) );
		}

		return $item;
	}

	/**
	 * Campos nativos de un post.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	private function native_fields( WP_Post $post ): array {
		return array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'status'   => $post->post_status,
			'date'     => mysql_to_rfc3339( $post->post_date_gmt ),
			'modified' => mysql_to_rfc3339( $post->post_modified_gmt ),
			'slug'     => $post->post_name,
			'link'     => (string) get_permalink( $post ),
			'author'   => (int) $post->post_author,
		);
	}

	/**
	 * Lee y castea el valor de un campo meta.
	 *
	 * La lectura sale de la cache de meta que WP_Query ya precargo en lote:
	 * get_post_meta() no dispara una consulta por campo si la cache esta
	 * caliente.
	 *
	 * @param int             $post_id ID del post.
	 * @param FieldDefinition $field   Campo.
	 * @return mixed
	 */
	private function meta_value( int $post_id, FieldDefinition $field ) {
		$raw = get_post_meta( $post_id, $field->storage_key, $field->single );

		if ( '' === $raw || null === $raw || array() === $raw ) {
			return $field->single ? null : array();
		}

		return $this->cast( $raw, $field->type );
	}

	/**
	 * Castea un valor al tipo declarado en el esquema.
	 *
	 * @param mixed  $value Valor.
	 * @param string $type  Tipo.
	 * @return mixed
	 */
	private function cast( $value, string $type ) {
		if ( is_array( $value ) ) {
			return array_map(
				function ( $item ) use ( $type ) {
					return $this->cast( $item, $type );
				},
				$value
			);
		}

		switch ( $type ) {
			case FieldDefinition::TYPE_INTEGER:
				return (int) $value;

			case FieldDefinition::TYPE_NUMBER:
				return (float) $value;

			case FieldDefinition::TYPE_BOOLEAN:
				return (bool) $value;

			default:
				return $value;
		}
	}

	/**
	 * Nombres de campo disponibles para un recurso.
	 *
	 * @param ResourceDefinition $definition Definicion.
	 * @return string[]
	 */
	public function available_fields( ResourceDefinition $definition ): array {
		$names = self::NATIVE;

		foreach ( $definition->fields as $field ) {
			$names[] = $field->exposed_name;
		}

		return array_values( array_unique( $names ) );
	}
}
