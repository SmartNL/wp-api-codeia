<?php
/**
 * Decision de acceso: las dos puertas.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Permissions;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\EventDispatcher;

/**
 * Evalua si una identidad puede realizar una operacion.
 *
 * Una peticion atraviesa DOS comprobaciones independientes y ambas deben
 * pasar:
 *
 *   1. La matriz del plugin: que expone la API hacia fuera.
 *   2. Las capabilities de WordPress: el modelo de permisos real del sitio.
 *
 * La matriz solo puede RESTRINGIR. Si pudiera ampliar, un usuario sin
 * edit_posts podria editar contenido por REST porque alguien marco mal una
 * casilla, y la API se convertiria en una via para eludir los permisos del
 * sitio. Es una decision estructural, no una opcion de configuracion.
 */
final class PermissionResolver {

	/**
	 * Matriz configurable.
	 *
	 * @var PermissionMatrix
	 */
	private PermissionMatrix $matrix;

	/**
	 * Traductor de capabilities.
	 *
	 * @var CapabilityMapper
	 */
	private CapabilityMapper $capabilities;

	/**
	 * Bus de eventos.
	 *
	 * @var EventDispatcher
	 */
	private EventDispatcher $events;

	/**
	 * Cache de decisiones dentro de la peticion.
	 *
	 * La resolucion ocurre por campo y por elemento: una respuesta de veinte
	 * propiedades con veintiocho campos son 560 evaluaciones. El conjunto
	 * depende del rol, no del post concreto, asi que se calcula una vez.
	 *
	 * @var array<string, bool>
	 */
	private array $cache = array();

	/**
	 * Construye el resolutor.
	 *
	 * @param PermissionMatrix $matrix       Matriz configurable.
	 * @param CapabilityMapper $capabilities Traductor de capabilities.
	 * @param EventDispatcher  $events       Bus de eventos.
	 */
	public function __construct(
		PermissionMatrix $matrix,
		CapabilityMapper $capabilities,
		EventDispatcher $events
	) {
		$this->matrix       = $matrix;
		$this->capabilities = $capabilities;
		$this->events       = $events;
	}

	/**
	 * Decide si el contexto tiene permiso.
	 *
	 * @param PermissionContext $context Contexto.
	 * @return bool
	 */
	public function can( PermissionContext $context ): bool {
		if ( ! PermissionContext::is_valid_operation( $context->operation ) ) {
			return false;
		}

		$key = $this->cache_key( $context );

		if ( null === $context->object_id && isset( $this->cache[ $key ] ) ) {
			return $this->cache[ $key ];
		}

		// Puerta 1: la matriz del plugin.
		$resolved          = $this->matrix->resolve( $context );
		$context->decision = (bool) $resolved['decision'];
		$context->level    = (int) $resolved['level'];

		$allowed = $context->decision;

		// Puerta 2: las capabilities reales de WordPress.
		if ( $allowed ) {
			$allowed = $this->capabilities->allows( $context );
		}

		/*
		 * El filtro tiene la ultima palabra por diseno: es el punto de
		 * extension para logica que la matriz no puede expresar. Recibe el
		 * contexto con la decision previa y el nivel que la produjo.
		 */
		$filtered = $this->events->filter( 'permissions/can', $allowed, $context );

		$result = is_bool( $filtered ) ? $filtered : $allowed;

		if ( null === $context->object_id ) {
			$this->cache[ $key ] = $result;
		}

		return $result;
	}

	/**
	 * Atajo para decisiones de recurso.
	 *
	 * @param int    $user_id   ID de usuario.
	 * @param string $resource_type  Recurso.
	 * @param string $operation Operacion.
	 * @param int    $object_id ID del objeto, 0 si no aplica.
	 * @return bool
	 */
	public function can_operate( int $user_id, string $resource_type, string $operation, int $object_id = 0 ): bool {
		return $this->can(
			new PermissionContext(
				$user_id,
				$this->effective_role( $user_id ),
				$resource_type,
				$operation,
				null,
				$object_id > 0 ? $object_id : null
			)
		);
	}

	/**
	 * Rol efectivo de un usuario.
	 *
	 * Se usa como eje de la matriz porque es lo que un administrador
	 * entiende, pero la comprobacion real siempre acaba en capabilities.
	 *
	 * @param int $user_id ID de usuario.
	 * @return string
	 */
	public function effective_role( int $user_id ): string {
		if ( 0 === $user_id ) {
			return 'anonymous';
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof \WP_User || array() === $user->roles ) {
			return 'anonymous';
		}

		return (string) reset( $user->roles );
	}

	/**
	 * Clave de cache de una decision.
	 *
	 * @param PermissionContext $context Contexto.
	 * @return string
	 */
	private function cache_key( PermissionContext $context ): string {
		return implode(
			'|',
			array(
				$context->role,
				$context->resource,
				$context->operation,
				(string) $context->field,
			)
		);
	}

	/**
	 * Vacia la cache de decisiones.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->cache = array();
	}
}
