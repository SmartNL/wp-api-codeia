<?php
/**
 * Registro diferido de rutas.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Api;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\EventDispatcher;
use WpApi\Codeia\Schema\SchemaRegistry;

/**
 * Deriva las rutas de la configuracion y las registra en rest_api_init.
 *
 * El registro es DIFERIDO: nada se registra hasta que llega una peticion
 * REST. Una carga de pagina normal del front-end no paga el coste de
 * construir definiciones ni de registrar rutas.
 */
final class RouteRegistrar {

	/**
	 * Registro de esquema.
	 *
	 * @var SchemaRegistry
	 */
	private SchemaRegistry $schema;

	/**
	 * Fabrica de controladores.
	 *
	 * @var ControllerFactory
	 */
	private ControllerFactory $factory;

	/**
	 * Configuracion.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Bus de eventos.
	 *
	 * @var EventDispatcher
	 */
	private EventDispatcher $events;

	/**
	 * Construye el registrador.
	 *
	 * @param SchemaRegistry    $schema  Registro de esquema.
	 * @param ControllerFactory $factory Fabrica de controladores.
	 * @param Config            $config  Configuracion.
	 * @param EventDispatcher   $events  Bus de eventos.
	 */
	public function __construct(
		SchemaRegistry $schema,
		ControllerFactory $factory,
		Config $config,
		EventDispatcher $events
	) {
		$this->schema  = $schema;
		$this->factory = $factory;
		$this->config  = $config;
		$this->events  = $events;
	}

	/**
	 * Registra las rutas de todos los recursos habilitados.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( $this->enabled_resources() as $post_type => $operations ) {
			$definition = $this->schema->definition_for( $post_type );

			if ( null === $definition ) {
				continue;
			}

			$this->factory->make( $definition )->register_resource_routes( $operations );
		}
	}

	/**
	 * Recursos habilitados y sus operaciones.
	 *
	 * Denegacion por defecto: un recurso sin marcar enabled no se expone, y
	 * un recurso sin operaciones declaradas no registra ninguna ruta.
	 *
	 * @return array<string, string[]>
	 */
	public function enabled_resources(): array {
		$resources = $this->config->get( 'resources', array() );

		if ( ! is_array( $resources ) ) {
			return array();
		}

		$enabled = array();

		foreach ( $resources as $post_type => $settings ) {
			if ( ! is_array( $settings ) || empty( $settings['enabled'] ) ) {
				continue;
			}

			$operations = isset( $settings['operations'] ) && is_array( $settings['operations'] )
				? array_values( array_map( 'strval', $settings['operations'] ) )
				: array();

			if ( array() === $operations ) {
				continue;
			}

			$enabled[ (string) $post_type ] = $operations;
		}

		$filtered = $this->events->filter( 'rest/routes', $enabled );

		return is_array( $filtered ) ? $filtered : $enabled;
	}

	/**
	 * Namespace REST completo.
	 *
	 * @return string
	 */
	public function rest_namespace(): string {
		return sprintf(
			'%s/%s',
			(string) $this->config->get( 'namespace', 'codeia' ),
			(string) $this->config->get( 'api_version', 'v1' )
		);
	}
}
