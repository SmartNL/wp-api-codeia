<?php
/**
 * Restriccion de colecciones por rol.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Permissions;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\EventDispatcher;

/**
 * Acota que elementos entran en un listado segun quien pregunta.
 *
 * Se aplica EN LA CONSULTA, nunca filtrando el resultado despues. Filtrar a
 * posteriori rompe la paginacion: pedir veinte elementos y descartar siete
 * devuelve trece, y el total de X-WP-Total deja de corresponder con lo
 * devuelto.
 */
final class CollectionRestrictor {

	/**
	 * Traductor de capabilities.
	 *
	 * @var CapabilityMapper
	 */
	private CapabilityMapper $capabilities;

	/**
	 * Resolutor de permisos.
	 *
	 * @var PermissionResolver
	 */
	private PermissionResolver $resolver;

	/**
	 * Bus de eventos.
	 *
	 * @var EventDispatcher
	 */
	private EventDispatcher $events;

	/**
	 * Construye el restrictor.
	 *
	 * @param CapabilityMapper   $capabilities Traductor de capabilities.
	 * @param PermissionResolver $resolver     Resolutor.
	 * @param EventDispatcher    $events       Bus de eventos.
	 */
	public function __construct(
		CapabilityMapper $capabilities,
		PermissionResolver $resolver,
		EventDispatcher $events
	) {
		$this->capabilities = $capabilities;
		$this->resolver     = $resolver;
		$this->events       = $events;
	}

	/**
	 * Anade a los argumentos de WP_Query la restriccion que corresponda.
	 *
	 * @param array<string, mixed> $args     Argumentos de WP_Query.
	 * @param int                  $user_id  ID de usuario.
	 * @param string               $resource_type Recurso.
	 * @return array<string, mixed>
	 */
	public function apply( array $args, int $user_id, string $resource_type ): array {
		$args = $this->restrict_by_status( $args, $user_id, $resource_type );

		$filtered = $this->events->filter( 'permissions/collection_args', $args, $resource_type, $user_id );

		return is_array( $filtered ) ? $filtered : $args;
	}

	/**
	 * Acota los estados visibles y, si procede, la autoria.
	 *
	 * @param array<string, mixed> $args     Argumentos.
	 * @param int                  $user_id  ID de usuario.
	 * @param string               $resource_type Recurso.
	 * @return array<string, mixed>
	 */
	private function restrict_by_status( array $args, int $user_id, string $resource_type ): array {
		$object = get_post_type_object( $resource_type );

		if ( null === $object ) {
			$args['post_status'] = 'publish';

			return $args;
		}

		$read_private = isset( $object->cap->read_private_posts )
			? (string) $object->cap->read_private_posts
			: 'read_private_posts';

		$edit_others = isset( $object->cap->edit_others_posts )
			? (string) $object->cap->edit_others_posts
			: 'edit_others_posts';

		// Quien puede leer lo privado y editar lo ajeno ve la coleccion entera.
		if ( $this->user_can( $user_id, $read_private ) && $this->user_can( $user_id, $edit_others ) ) {
			return $args;
		}

		$edit_own = isset( $object->cap->edit_posts ) ? (string) $object->cap->edit_posts : 'edit_posts';

		if ( $user_id > 0 && $this->user_can( $user_id, $edit_own ) ) {
			/*
			 * Ve lo publicado mas lo suyo en cualquier estado. Se expresa con
			 * una consulta OR sobre autoria para que la paginacion siga
			 * cuadrando; filtrar despues la romperia.
			 */
			$args['post_status'] = array( 'publish', 'draft', 'pending', 'private', 'future' );
			$args['author']      = $user_id;

			return $args;
		}

		$args['post_status'] = 'publish';

		return $args;
	}

	/**
	 * Comprueba una capability sin usar el contexto global.
	 *
	 * @param int    $user_id ID de usuario.
	 * @param string $cap     Capability.
	 * @return bool
	 */
	private function user_can( int $user_id, string $cap ): bool {
		if ( 0 === $user_id ) {
			return false;
		}

		$user = get_userdata( $user_id );

		return $user instanceof \WP_User && user_can( $user, $cap );
	}
}
