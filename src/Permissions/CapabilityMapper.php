<?php
/**
 * Traduccion de operaciones a capabilities de WordPress.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Permissions;

defined( 'ABSPATH' ) || exit;

/**
 * Deriva las capabilities de cada operacion desde el post type.
 *
 * Nunca se codifican a mano: se toman del capability_type declarado, de modo
 * que un CPT con capabilities propias funciona sin tocar el plugin.
 *
 * Las capabilities de OBJETO pasan por map_meta_cap, que es lo que hace que
 * un author pueda editar SUS entradas y no las ajenas. Comprobar solo la de
 * coleccion (edit_posts) y no la de objeto (edit_post con el ID) es el fallo
 * de autorizacion mas comun en endpoints REST personalizados: concede a
 * cualquier autor la edicion del contenido de todos los demas.
 */
final class CapabilityMapper {

	/**
	 * Capabilities de coleccion por operacion.
	 *
	 * @param string $post_type Post type.
	 * @param string $operation Operacion.
	 * @return string|null Capability, o null si la operacion no la exige.
	 */
	public function collection_cap( string $post_type, string $operation ): ?string {
		$object = get_post_type_object( $post_type );

		if ( null === $object ) {
			return null;
		}

		$caps = (array) $object->cap;

		switch ( $operation ) {
			case PermissionContext::OP_READ:
				return isset( $caps['read'] ) ? (string) $caps['read'] : 'read';

			case PermissionContext::OP_CREATE:
			case PermissionContext::OP_UPDATE:
				return isset( $caps['edit_posts'] ) ? (string) $caps['edit_posts'] : 'edit_posts';

			case PermissionContext::OP_DELETE:
				return isset( $caps['delete_posts'] ) ? (string) $caps['delete_posts'] : 'delete_posts';

			case PermissionContext::OP_UPLOAD:
				return 'upload_files';

			default:
				return null;
		}
	}

	/**
	 * Capability de objeto por operacion.
	 *
	 * @param string $operation Operacion.
	 * @return string|null Capability meta, o null si la operacion no opera
	 *                     sobre un objeto concreto.
	 */
	public function object_cap( string $operation ): ?string {
		switch ( $operation ) {
			case PermissionContext::OP_READ:
				return 'read_post';

			case PermissionContext::OP_UPDATE:
			case PermissionContext::OP_UPLOAD:
				return 'edit_post';

			case PermissionContext::OP_DELETE:
				return 'delete_post';

			default:
				return null;
		}
	}

	/**
	 * Comprueba la puerta de capabilities de WordPress.
	 *
	 * Es la segunda puerta: la matriz del plugin solo puede RESTRINGIR, nunca
	 * ampliar. Sustituir esta comprobacion por la matriz convertiria la API en
	 * una via para eludir el modelo de permisos del sitio.
	 *
	 * @param PermissionContext $context Contexto.
	 * @return bool
	 */
	public function allows( PermissionContext $context ): bool {
		$collection = $this->collection_cap( $context->resource, $context->operation );

		if ( null !== $collection && ! $this->user_can( $context->user_id, $collection ) ) {
			return false;
		}

		if ( null === $context->object_id ) {
			return true;
		}

		$object_cap = $this->object_cap( $context->operation );

		if ( null === $object_cap ) {
			return true;
		}

		return $this->user_can( $context->user_id, $object_cap, $context->object_id );
	}

	/**
	 * Comprueba una capability para un usuario concreto.
	 *
	 * Se usa user_can() y no current_user_can() porque la decision puede
	 * evaluarse para una identidad que no es la del contexto global, por
	 * ejemplo al previsualizar la matriz desde el dashboard.
	 *
	 * NUNCA se compara el nombre del rol: los roles son agrupaciones mutables
	 * de capabilities, y cualquier plugin de membresia o tienda las altera.
	 *
	 * @param int      $user_id ID de usuario, 0 para anonimo.
	 * @param string   $cap     Capability.
	 * @param int|null $object_id ID del objeto, si aplica.
	 * @return bool
	 */
	private function user_can( int $user_id, string $cap, ?int $object_id = null ): bool {
		if ( 0 === $user_id ) {
			// Un anonimo solo tiene la capability de lectura publica.
			return 'read' === $cap;
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		return null === $object_id
			? user_can( $user, $cap )
			: user_can( $user, $cap, $object_id );
	}
}
