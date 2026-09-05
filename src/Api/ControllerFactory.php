<?php
/**
 * Fabrica de controladores de recurso.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Api;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Permissions\CollectionRestrictor;
use WpApi\Codeia\Permissions\FieldVisibility;
use WpApi\Codeia\Permissions\PermissionResolver;
use WpApi\Codeia\Schema\ResourceDefinition;

/**
 * Construye un ResourceController parametrizado por su definicion.
 *
 * No se genera una clase por post type: el mismo controlador sirve a todos,
 * cambiando solo la ResourceDefinition que recibe.
 */
final class ControllerFactory {

	/**
	 * Resolutor de permisos.
	 *
	 * @var PermissionResolver
	 */
	private PermissionResolver $permissions;

	/**
	 * Visibilidad por campo.
	 *
	 * @var FieldVisibility
	 */
	private FieldVisibility $visibility;

	/**
	 * Restrictor de colecciones.
	 *
	 * @var CollectionRestrictor
	 */
	private CollectionRestrictor $restrictor;

	/**
	 * Proyector de campos.
	 *
	 * @var FieldProjector
	 */
	private FieldProjector $projector;

	/**
	 * Constructor de consultas.
	 *
	 * @var QueryBuilder
	 */
	private QueryBuilder $queries;

	/**
	 * Paginador por cursor.
	 *
	 * @var CursorPaginator
	 */
	private CursorPaginator $cursors;

	/**
	 * Configuracion del plugin.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Construye la fabrica.
	 *
	 * @param PermissionResolver   $permissions Resolutor.
	 * @param FieldVisibility      $visibility  Visibilidad.
	 * @param CollectionRestrictor $restrictor  Restrictor.
	 * @param FieldProjector       $projector   Proyector.
	 * @param QueryBuilder         $queries     Constructor de consultas.
	 * @param CursorPaginator      $cursors     Paginador.
	 * @param Config               $config      Configuracion.
	 */
	public function __construct(
		PermissionResolver $permissions,
		FieldVisibility $visibility,
		CollectionRestrictor $restrictor,
		FieldProjector $projector,
		QueryBuilder $queries,
		CursorPaginator $cursors,
		Config $config
	) {
		$this->permissions = $permissions;
		$this->visibility  = $visibility;
		$this->restrictor  = $restrictor;
		$this->projector   = $projector;
		$this->queries     = $queries;
		$this->cursors     = $cursors;
		$this->config      = $config;
	}

	/**
	 * Crea el controlador de un recurso.
	 *
	 * @param ResourceDefinition $definition Definicion.
	 * @return ResourceController
	 */
	public function make( ResourceDefinition $definition ): ResourceController {
		return new ResourceController(
			$definition,
			$this->permissions,
			$this->visibility,
			$this->restrictor,
			$this->projector,
			$this->queries,
			$this->cursors,
			sprintf(
				'%s/%s',
				(string) $this->config->get( 'namespace', 'codeia' ),
				(string) $this->config->get( 'api_version', 'v1' )
			),
			(int) $this->config->get( 'limits.per_page_max', 100 )
		);
	}
}
