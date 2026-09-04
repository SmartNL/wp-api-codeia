<?php
/**
 * Visibilidad y escritura por campo.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Permissions;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\EventDispatcher;

/**
 * Decide que campos ve y que campos escribe cada rol.
 *
 * La asimetria entre lectura y escritura es deliberada:
 *
 * - En LECTURA los campos vetados se eliminan en silencio. Devolverlos como
 *   null confirmaria que el campo existe y que hay algo que ocultar; la
 *   ausencia no distingue "no tienes permiso" de "no tiene valor", y esa
 *   ambiguedad es deseable.
 *
 * - En ESCRITURA se rechaza con error. Descartar en silencio devolveria un
 *   200 a un cliente que cree haber guardado el dato, una inconsistencia que
 *   aparece mucho despues. El cliente NECESITA saber que su intencion no se
 *   cumplio; en lectura no tiene ninguna intencion que frustrar.
 */
final class FieldVisibility {

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
	 * Construye el servicio.
	 *
	 * @param PermissionResolver $resolver Resolutor.
	 * @param EventDispatcher    $events   Bus de eventos.
	 */
	public function __construct( PermissionResolver $resolver, EventDispatcher $events ) {
		$this->resolver = $resolver;
		$this->events   = $events;
	}

	/**
	 * Indica si un rol puede leer un campo.
	 *
	 * @param int    $user_id  ID de usuario.
	 * @param string $resource_type Recurso.
	 * @param string $field    Nombre publico del campo.
	 * @return bool
	 */
	public function can_read( int $user_id, string $resource_type, string $field ): bool {
		$allowed = $this->resolver->can(
			new PermissionContext(
				$user_id,
				$this->resolver->effective_role( $user_id ),
				$resource_type,
				PermissionContext::OP_READ,
				$field
			)
		);

		$filtered = $this->events->filter( 'permissions/field_visible', $allowed, $field, $resource_type, $user_id );

		return is_bool( $filtered ) ? $filtered : $allowed;
	}

	/**
	 * Indica si un rol puede escribir un campo.
	 *
	 * @param int    $user_id  ID de usuario.
	 * @param string $resource_type Recurso.
	 * @param string $field    Nombre publico del campo.
	 * @return bool
	 */
	public function can_write( int $user_id, string $resource_type, string $field ): bool {
		$allowed = $this->resolver->can(
			new PermissionContext(
				$user_id,
				$this->resolver->effective_role( $user_id ),
				$resource_type,
				PermissionContext::OP_UPDATE,
				$field
			)
		);

		$filtered = $this->events->filter( 'permissions/field_writable', $allowed, $field, $resource_type, $user_id );

		return is_bool( $filtered ) ? $filtered : $allowed;
	}

	/**
	 * Proyecta un elemento dejando solo los campos legibles.
	 *
	 * Los campos vetados se OMITEN, no se ponen a null.
	 *
	 * @param array<string, mixed> $item     Representacion del elemento.
	 * @param int                  $user_id  ID de usuario.
	 * @param string               $resource_type Recurso.
	 * @return array<string, mixed>
	 */
	public function project( array $item, int $user_id, string $resource_type ): array {
		$visible = array();

		foreach ( $item as $field => $value ) {
			if ( $this->can_read( $user_id, $resource_type, (string) $field ) ) {
				$visible[ $field ] = $value;
			}
		}

		return $visible;
	}

	/**
	 * Devuelve los campos de una escritura que el rol no puede tocar.
	 *
	 * El llamador rechaza la peticion ENTERA si la lista no esta vacia: una
	 * escritura no se aplica parcialmente.
	 *
	 * @param array<string, mixed> $payload  Campos entrantes.
	 * @param int                  $user_id  ID de usuario.
	 * @param string               $resource_type Recurso.
	 * @return string[] Nombres de los campos vetados.
	 */
	public function forbidden_writes( array $payload, int $user_id, string $resource_type ): array {
		$forbidden = array();

		foreach ( array_keys( $payload ) as $field ) {
			if ( ! $this->can_write( $user_id, $resource_type, (string) $field ) ) {
				$forbidden[] = (string) $field;
			}
		}

		return $forbidden;
	}

	/**
	 * Conjunto de campos legibles por un rol, para cachear la respuesta.
	 *
	 * El hash de este conjunto entra en la clave de cache: asi dos usuarios
	 * con la misma visibilidad comparten entrada, sin que la respuesta de uno
	 * se sirva al otro.
	 *
	 * @param string[] $fields   Campos candidatos.
	 * @param int      $user_id  ID de usuario.
	 * @param string   $resource_type Recurso.
	 * @return string[]
	 */
	public function readable_fields( array $fields, int $user_id, string $resource_type ): array {
		return array_values(
			array_filter(
				$fields,
				function ( string $field ) use ( $user_id, $resource_type ): bool {
					return $this->can_read( $user_id, $resource_type, $field );
				}
			)
		);
	}

	/**
	 * Hash del conjunto de campos legibles.
	 *
	 * @param string[] $fields   Campos candidatos.
	 * @param int      $user_id  ID de usuario.
	 * @param string   $resource_type Recurso.
	 * @return string
	 */
	public function fields_hash( array $fields, int $user_id, string $resource_type ): string {
		$readable = $this->readable_fields( $fields, $user_id, $resource_type );
		sort( $readable );

		return substr( md5( implode( ',', $readable ) ), 0, 8 );
	}
}
